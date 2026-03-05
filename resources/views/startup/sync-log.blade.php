<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sync Log</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-slate-100 text-slate-900">
<main class="mx-auto w-full max-w-6xl px-6 py-8">
    <h1 class="text-2xl font-semibold">Sync Log</h1>
    <p class="mt-2 text-sm text-slate-600">Latest outbound sync events.</p>

    <div class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table class="w-full text-left text-sm">
            <thead class="bg-slate-50">
            <tr>
                <th class="px-4 py-3">ID</th>
                <th class="px-4 py-3">Entity</th>
                <th class="px-4 py-3">Operation</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3">Error</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($rows as $row)
                <tr class="border-t border-slate-200">
                    <td class="px-4 py-3">{{ $row->id }}</td>
                    <td class="px-4 py-3">{{ $row->entity_type }}</td>
                    <td class="px-4 py-3">{{ $row->operation }}</td>
                    <td class="px-4 py-3">{{ $row->status }}</td>
                    <td class="px-4 py-3 text-xs text-red-700">{{ $row->error }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-4 py-6 text-center text-slate-500">No sync events.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">
        <a href="{{ route('startup.index') }}"
           class="text-sm text-slate-600 hover:text-slate-900">Back to dashboard</a>
    </div>
</main>
</body>
</html>
