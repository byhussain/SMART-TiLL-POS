<?php

namespace App\Http\Controllers;

use App\Jobs\SyncCloudStoreData;
use App\Models\Store;
use App\Models\SyncOutbox;
use App\Services\CloudSyncService;
use App\Services\PosSystemUserService;
use App\Services\RuntimeStateService;
use Filament\Notifications\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use SmartTill\Core\Models\Country;
use SmartTill\Core\Models\Currency;
use SmartTill\Core\Models\Timezone;
use Throwable;

class StartupController extends Controller
{
    public function __construct(
        private readonly RuntimeStateService $runtimeStateService,
        private readonly PosSystemUserService $systemUserService,
        private readonly CloudSyncService $cloudSyncService,
    ) {}

    public function index(): View|RedirectResponse
    {
        $state = $this->runtimeStateService->get();

        if ($state->has_completed_onboarding) {
            $this->systemUserService->ensureAuthenticated();

            $store = Store::query()->find($state->active_store_id) ?? Store::query()->latest('id')->first();

            if (! $store) {
                $state->has_completed_onboarding = false;
                $state->save();
            } else {
                if (
                    $state->mode === 'cloud'
                    && (int) ($store->server_id ?? 0) > 0
                    && ! $this->runtimeStateService->isStoreBootstrapped((int) $store->id)
                ) {
                    return to_route('startup.cloud.bootstrap', ['store' => $store->id]);
                }

                return to_route('filament.store.pages.dashboard', ['tenant' => $store->id]);
            }
        }

        return view('startup.index');
    }

    public function continueAsGuest(): RedirectResponse
    {
        $systemUser = $this->systemUserService->ensureAuthenticated();
        $store = Store::query()->latest('id')->first();

        if (! $store) {
            return to_route('startup.guest.setup');
        }

        $this->systemUserService->ensureStoreAttached($systemUser, $store);
        $this->runtimeStateService->completeGuestOnboarding($store);

        return to_route('filament.store.pages.dashboard', ['tenant' => $store->id]);
    }

    public function guestSetupForm(): View|RedirectResponse
    {
        if ($this->runtimeStateService->get()->has_completed_onboarding) {
            return to_route('startup.index');
        }

        $countries = Country::query()
            ->with([
                'currencies:id,name,code',
                'timezones:id,name',
            ])
            ->orderBy('name')
            ->get(['id', 'name']);

        $currencies = $countries
            ->flatMap(fn (Country $country) => $country->currencies->map(fn (Currency $currency) => (object) [
                'id' => $currency->id,
                'name' => $currency->name,
                'code' => $currency->code,
                'country_id' => $country->id,
            ]))
            ->unique(fn (object $currency) => $currency->country_id.'-'.$currency->id)
            ->values();

        $timezones = $countries
            ->flatMap(fn (Country $country) => $country->timezones->map(fn (Timezone $timezone) => (object) [
                'id' => $timezone->id,
                'name' => $timezone->name,
                'country_id' => $country->id,
            ]))
            ->unique(fn (object $timezone) => $timezone->country_id.'-'.$timezone->id)
            ->values();

        return view('startup.guest-setup', [
            'countries' => $countries,
            'currencies' => $currencies,
            'timezones' => $timezones,
        ]);
    }

    public function guestSetupStore(Request $request): RedirectResponse
    {
        if ($this->runtimeStateService->get()->has_completed_onboarding) {
            return to_route('startup.index');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'currency_id' => [
                'required',
                'integer',
                Rule::exists('country_currency', 'currency_id')
                    ->where(fn ($query) => $query->where('country_id', (int) $request->input('country_id'))),
            ],
            'timezone_id' => [
                'required',
                'integer',
                Rule::exists('country_timezone', 'timezone_id')
                    ->where(fn ($query) => $query->where('country_id', (int) $request->input('country_id'))),
            ],
        ]);

        $store = Store::query()->create($validated);
        $systemUser = $this->systemUserService->ensureAuthenticated();
        $this->systemUserService->ensureStoreAttached($systemUser, $store);
        $this->runtimeStateService->completeGuestOnboarding($store);

        return to_route('filament.store.pages.dashboard', ['tenant' => $store->id]);
    }

    public function cloudForm(): View
    {
        return view('startup.cloud-auth', [
            'baseUrl' => $this->resolveCloudBaseUrl(),
        ]);
    }

    public function cloudLogin(Request $request): JsonResponse
    {
        $baseUrl = $this->resolveCloudBaseUrl() ?? $this->resolveCloudBaseUrlFromRequest($request);
        if (! $baseUrl) {
            return $this->respondCloudError($request, 'Cloud server URL is not configured.');
        }

        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        try {
            $response = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->post('/api/pos/login', [
                    'email' => $validated['email'],
                    'password' => $validated['password'],
                ]);

            if (! $response->successful()) {
                return $this->respondCloudError(
                    $request,
                    $this->resolveCloudErrorMessage($response->json(), 'Unable to login to cloud server.')
                );
            }

            $token = (string) $response->json('token');
            $cloudUserId = (int) ($response->json('user.data.id') ?? $response->json('user.id') ?? 0);
        } catch (RuntimeException $runtimeException) {
            return $this->respondCloudError($request, $runtimeException->getMessage());
        } catch (Throwable $throwable) {
            return $this->respondCloudError($request, 'Unable to connect to cloud server. '.$throwable->getMessage());
        }

        session([
            'pos_cloud_token' => $token,
            'pos_cloud_base_url' => $baseUrl,
            'pos_cloud_user_id' => $cloudUserId,
            'pos_cloud_stores' => [],
        ]);
        $this->runtimeStateService->persistCloudCredentials($cloudUserId, $token, $baseUrl);

        return response()->json([
            'message' => 'Cloud login successful.',
            'redirect' => route('startup.cloud.stores'),
        ]);
    }

    public function cloudRegister(Request $request): JsonResponse
    {
        $baseUrl = $this->resolveCloudBaseUrl() ?? $this->resolveCloudBaseUrlFromRequest($request);
        if (! $baseUrl) {
            return $this->respondCloudError($request, 'Cloud server URL is not configured.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8'],
            'password_confirmation' => ['required', 'same:password'],
        ]);

        try {
            $response = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->post('/api/pos/register', [
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'password' => $validated['password'],
                    'password_confirmation' => $validated['password_confirmation'],
                ]);

            if (! $response->successful()) {
                return $this->respondCloudError(
                    $request,
                    $this->resolveCloudErrorMessage($response->json(), 'Unable to register on cloud server.')
                );
            }

            $token = (string) $response->json('token');
            $cloudUserId = (int) ($response->json('user.data.id') ?? $response->json('user.id') ?? 0);
        } catch (RuntimeException $runtimeException) {
            return $this->respondCloudError($request, $runtimeException->getMessage());
        } catch (Throwable $throwable) {
            return $this->respondCloudError($request, 'Unable to connect to cloud server. '.$throwable->getMessage());
        }

        session([
            'pos_cloud_token' => $token,
            'pos_cloud_base_url' => $baseUrl,
            'pos_cloud_user_id' => $cloudUserId,
            'pos_cloud_stores' => [],
        ]);
        $this->runtimeStateService->persistCloudCredentials($cloudUserId, $token, $baseUrl);

        return response()->json([
            'message' => 'Cloud registration successful.',
            'redirect' => route('startup.cloud.stores'),
        ]);
    }

    public function cloudStores(Request $request): View|JsonResponse|RedirectResponse
    {
        $baseUrl = trim((string) session('pos_cloud_base_url'));
        $token = trim((string) session('pos_cloud_token'));

        if ($baseUrl === '' || $token === '') {
            $state = $this->runtimeStateService->get();
            $baseUrl = trim((string) ($state->cloud_base_url ?? ''));
            $token = trim((string) ($state->cloud_token ?? ''));

            if ($baseUrl !== '' && $token !== '') {
                session([
                    'pos_cloud_base_url' => $baseUrl,
                    'pos_cloud_token' => $token,
                    'pos_cloud_user_id' => (int) ($state->cloud_user_id ?? 0),
                ]);
            }
        }

        if ($baseUrl === '' || $token === '') {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Cloud session is not available. Please login again.',
                ], 422);
            }

            return to_route('startup.cloud.form');
        }

        try {
            $stores = $this->cloudSyncService->fetchStores($baseUrl, $token);
        } catch (RuntimeException $runtimeException) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $runtimeException->getMessage(),
                ], 422);
            }

            return to_route('startup.cloud.form')->withErrors([
                'email' => $runtimeException->getMessage(),
            ]);
        }

        if (empty($stores)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'No stores found for this cloud account.',
                ], 404);
            }

            return to_route('startup.cloud.form')->withErrors([
                'email' => 'No stores found for this cloud account.',
            ]);
        }

        $this->cloudSyncService->ensureLocalStoresFromServer($stores);
        session(['pos_cloud_stores' => $stores]);

        if ($request->expectsJson()) {
            return response()->json([
                'data' => $stores,
            ]);
        }

        return view('startup.cloud-stores', ['stores' => $stores]);
    }

    public function selectCloudStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'server_store_id' => ['required', 'integer'],
        ]);

        $stores = (array) session('pos_cloud_stores', []);
        $selected = collect($stores)->firstWhere('id', (int) $validated['server_store_id']);

        if (! is_array($selected)) {
            return $this->respondCloudError($request, 'Invalid cloud store selected.');
        }

        $baseUrl = trim((string) session('pos_cloud_base_url'));
        $token = trim((string) session('pos_cloud_token'));
        $cloudUserId = (int) session('pos_cloud_user_id');

        if ($baseUrl === '' || $token === '') {
            $state = $this->runtimeStateService->get();
            $baseUrl = trim((string) ($state->cloud_base_url ?? ''));
            $token = trim((string) ($state->cloud_token ?? ''));
            $cloudUserId = $cloudUserId > 0 ? $cloudUserId : (int) ($state->cloud_user_id ?? 0);
        }

        if ($baseUrl === '' || $token === '') {
            return $this->respondCloudError($request, 'Cloud session is not available. Please login again.');
        }

        $localStores = $this->cloudSyncService->ensureLocalStoresFromServer($stores);
        $localStore = collect($localStores)->first(
            fn (Store $store): bool => (int) ($store->server_id ?? 0) === (int) $validated['server_store_id']
        );

        if (! $localStore) {
            return $this->respondCloudError($request, 'Unable to create local store mapping.');
        }

        $systemUser = $this->systemUserService->ensureAuthenticated();
        $this->systemUserService->ensureStoresAttached($systemUser, $localStores);
        $this->runtimeStateService->completeCloudOnboarding($localStore, $cloudUserId, $token, $baseUrl);
        SyncCloudStoreData::dispatch((int) $localStore->id, 'bootstrap');

        $redirect = route('startup.cloud.bootstrap', ['store' => $localStore->id]);

        return response()->json([
            'message' => 'Cloud store connected successfully. Store data download has started.',
            'redirect' => $redirect,
        ]);
    }

    public function cloudBootstrap(Store $store): View|RedirectResponse
    {
        $state = $this->runtimeStateService->get();

        if (
            $state->mode !== 'cloud'
            || ! $state->cloud_token_present
            || ! filled($state->cloud_base_url)
            || ! filled($state->cloud_token)
            || (int) ($store->server_id ?? 0) <= 0
        ) {
            return to_route('startup.cloud.form');
        }

        $this->systemUserService->ensureAuthenticated();
        $this->runtimeStateService->setActiveStore($store);

        if ($this->runtimeStateService->isStoreBootstrapped((int) $store->id)) {
            return to_route('filament.store.pages.dashboard', ['tenant' => $store->id]);
        }

        return view('startup.cloud-bootstrap', [
            'store' => $store,
            'dashboardUrl' => route('filament.store.pages.dashboard', ['tenant' => $store->id]),
            'syncStatusUrl' => route('startup.cloud.sync-status'),
            'syncNowUrl' => route('startup.cloud.sync-now'),
            'syncLogUrl' => route('startup.cloud.sync-log'),
        ]);
    }

    public function disconnectCloud(): RedirectResponse
    {
        $this->runtimeStateService->markDisconnected();
        session()->forget(['pos_cloud_token', 'pos_cloud_base_url', 'pos_cloud_user_id', 'pos_cloud_stores']);
        Notification::make()
            ->title('Cloud disconnected')
            ->success()
            ->send();

        return back();
    }

    public function syncNow(Request $request): RedirectResponse|JsonResponse
    {
        $state = $this->runtimeStateService->get();
        $store = Store::query()->find($state->active_store_id);

        if (! $store || ! $state->cloud_token_present || ! $state->cloud_base_url || ! $state->cloud_token) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Cloud is not connected.',
                ], 422);
            }

            Notification::make()
                ->title('Cloud is not connected')
                ->danger()
                ->send();

            return back()->withErrors(['cloud' => 'Cloud is not connected.']);
        }

        $this->cloudSyncService->resetSyncFailuresForStore((int) $store->id);
        $action = $this->runtimeStateService->isStoreBootstrapped((int) $store->id) ? 'delta' : 'bootstrap';
        SyncCloudStoreData::dispatch((int) $store->id, $action);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $action === 'bootstrap'
                    ? 'Store bootstrap started in background.'
                    : 'Delta sync started in background.',
                'queued' => true,
                'store_id' => (int) $store->id,
                'action' => $action,
            ]);
        }

        Notification::make()
            ->title($action === 'bootstrap' ? 'Store bootstrap started in background' : 'Delta sync started in background')
            ->success()
            ->send();

        return back()->with('status', 'Cloud sync has been queued.');
    }

    public function syncModule(Request $request): RedirectResponse|JsonResponse
    {
        $modules = array_keys($this->cloudSyncService->getSyncModules());

        $validated = $request->validate([
            'module' => ['required', Rule::in($modules)],
        ]);

        $state = $this->runtimeStateService->get();
        $store = Store::query()->find($state->active_store_id);

        if (! $store || ! $state->cloud_token_present || ! $state->cloud_base_url || ! $state->cloud_token) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Cloud is not connected.',
                ], 422);
            }

            Notification::make()
                ->title('Cloud is not connected')
                ->danger()
                ->send();

            return back()->withErrors(['cloud' => 'Cloud is not connected.']);
        }

        $this->cloudSyncService->resetSyncFailuresForStoreModule((int) $store->id, (string) $validated['module']);
        SyncCloudStoreData::dispatch((int) $store->id, 'delta', (string) $validated['module']);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Module delta sync started in background.',
                'queued' => true,
                'store_id' => (int) $store->id,
                'module' => (string) $validated['module'],
            ]);
        }

        Notification::make()
            ->title('Module delta sync started in background')
            ->success()
            ->send();

        return back()->with('status', 'Module sync has been queued.');
    }

    public function syncLog(): View
    {
        return view('startup.sync-log', [
            'rows' => SyncOutbox::query()->latest('id')->limit(100)->get(),
        ]);
    }

    public function syncStatus(): JsonResponse
    {
        $state = $this->runtimeStateService->get();
        $store = Store::query()->find($state->active_store_id);
        $modules = array_keys($this->cloudSyncService->getSyncModules());
        $moduleSyncing = collect($modules)->mapWithKeys(fn (string $module): array => [$module => false])->all();

        if (! $store || ! $state->cloud_token_present || ! $state->cloud_base_url || ! $state->cloud_token) {
            return response()->json([
                'connected' => false,
                'is_syncing' => false,
                'has_errors' => false,
                'is_bootstrapping' => false,
                'bootstrap_status' => 'not_started',
                'bootstrap_progress_percent' => 0,
                'bootstrap_progress_label' => null,
                'module_syncing' => $moduleSyncing,
            ]);
        }

        $queuedSyncJobs = collect();
        if (Schema::hasTable('jobs')) {
            $queuedSyncJobs = DB::table('jobs')
                ->where('payload', 'like', '%SyncCloudStoreData%')
                ->get(['payload', 'reserved_at', 'available_at', 'created_at']);
        }

        $isSyncing = false;
        $detectedModuleSyncing = $moduleSyncing;
        $storeIdPattern = '/s:7:"storeId";i:(\d+);/';
        $modulePattern = '/s:6:"module";(?:N;|s:\d+:"([^"]*)";)/';
        $activeStoreId = (int) $store->id;
        $activeReservedThreshold = now()->subMinutes(2)->timestamp;

        foreach ($queuedSyncJobs as $queuedSyncJob) {
            $payload = $queuedSyncJob->payload ?? null;
            if (! is_string($payload)) {
                continue;
            }

            $commandPayload = $payload;
            $decodedPayload = json_decode($payload, true);
            if (is_array($decodedPayload)) {
                $decodedCommand = data_get($decodedPayload, 'data.command');
                if (is_string($decodedCommand) && $decodedCommand !== '') {
                    $commandPayload = $decodedCommand;
                }
            }

            if (! preg_match($storeIdPattern, $commandPayload, $storeMatches)) {
                continue;
            }

            $payloadStoreId = (int) ($storeMatches[1] ?? 0);
            if ($payloadStoreId !== $activeStoreId) {
                continue;
            }

            $reservedAt = is_numeric($queuedSyncJob->reserved_at ?? null)
                ? (int) $queuedSyncJob->reserved_at
                : null;

            if ($reservedAt !== null && $reservedAt < $activeReservedThreshold) {
                continue;
            }

            $isSyncing = true;

            if (preg_match($modulePattern, $commandPayload, $moduleMatches)) {
                $module = trim((string) ($moduleMatches[1] ?? ''));
                if ($module !== '' && array_key_exists($module, $detectedModuleSyncing)) {
                    $detectedModuleSyncing[$module] = true;
                }
            }
        }

        $syncErrorOverview = $this->cloudSyncService->getSyncErrorOverview($activeStoreId);
        $outboxErrorOverview = $this->cloudSyncService->getOutboxErrorOverviewForStore($activeStoreId);
        $hasSyncQueueFailures = false;

        if (Schema::hasTable('failed_jobs')) {
            $isFailedJobForActiveStore = function (mixed $payload, int $storeId) use ($storeIdPattern): bool {
                if (! is_string($payload) || $storeId <= 0) {
                    return false;
                }

                $commandPayload = $payload;
                $decodedPayload = json_decode($payload, true);
                if (is_array($decodedPayload)) {
                    $decodedCommand = data_get($decodedPayload, 'data.command');
                    if (is_string($decodedCommand) && $decodedCommand !== '') {
                        $commandPayload = $decodedCommand;
                    }
                }

                return preg_match($storeIdPattern, $commandPayload, $matches) === 1
                    && (int) ($matches[1] ?? 0) === $storeId;
            };

            $hasSyncQueueFailures = DB::table('failed_jobs')
                ->where('payload', 'like', '%SyncCloudStoreData%')
                ->orderByDesc('id')
                ->limit(100)
                ->get(['payload'])
                ->contains(fn ($job): bool => $isFailedJobForActiveStore($job->payload ?? null, $activeStoreId));
        }

        $hasErrors = (bool) ($syncErrorOverview['has_sync_errors'] ?? false)
            || (int) ($outboxErrorOverview['total_failed'] ?? 0) > 0
            || $hasSyncQueueFailures;

        if ($hasSyncQueueFailures && ! $isSyncing) {
            $detectedModuleSyncing = $moduleSyncing;
        }

        $bootstrapStatus = (string) ($state->bootstrap_status ?? 'not_started');
        $isBootstrapping = in_array($bootstrapStatus, ['downloading', 'installing'], true);

        return response()->json([
            'connected' => true,
            'is_syncing' => $isSyncing && ! $isBootstrapping,
            'is_bootstrapping' => $isBootstrapping,
            'bootstrap_status' => $bootstrapStatus,
            'bootstrap_progress_percent' => (int) ($state->bootstrap_progress_percent ?? 0),
            'bootstrap_progress_label' => $state->bootstrap_progress_label,
            'has_errors' => $hasErrors,
            'module_syncing' => $detectedModuleSyncing,
        ]);
    }

    private function resolveCloudBaseUrl(): ?string
    {
        $stateUrl = trim((string) ($this->runtimeStateService->get()->cloud_base_url ?? ''));
        if ($stateUrl !== '') {
            return rtrim($stateUrl, '/');
        }

        $configUrl = trim((string) config('services.pos_cloud.base_url', ''));
        if ($configUrl !== '') {
            return rtrim($configUrl, '/');
        }

        return null;
    }

    private function resolveCloudBaseUrlFromRequest(Request $request): ?string
    {
        $url = trim((string) $request->input('server_url', ''));
        if ($url === '') {
            return null;
        }

        $url = rtrim($url, '/');

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return $url;
    }

    private function resolveCloudErrorMessage(mixed $payload, string $fallback): string
    {
        if (! is_array($payload)) {
            return $fallback;
        }

        if (is_string($payload['message'] ?? null) && trim($payload['message']) !== '') {
            return trim($payload['message']);
        }

        $error = $payload['error'] ?? null;
        if (is_string($error) && trim($error) !== '') {
            return trim($error);
        }

        $errors = $payload['errors'] ?? null;
        if (is_array($errors)) {
            $first = collect($errors)
                ->flatten()
                ->first(fn ($value): bool => is_string($value) && trim($value) !== '');

            if (is_string($first) && trim($first) !== '') {
                return trim($first);
            }
        }

        return $fallback;
    }

    private function respondCloudError(Request $request, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
        ], 422);
    }
}
