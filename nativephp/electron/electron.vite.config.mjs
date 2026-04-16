import { join } from 'path';
import { defineConfig, externalizeDepsPlugin } from 'electron-vite';

const electronRoot = process.cwd();

export default defineConfig({
    main: {
        build: {
            rollupOptions: {
                plugins: [
                    {
                        name: 'watch-external',
                        buildStart() {
                            this.addWatchFile(join(process.env.APP_PATH, 'app', 'Providers', 'NativeAppServiceProvider.php'));
                        }
                    }
                ]
            }
        },
        resolve: {
            alias: {
                '#plugin': join(electronRoot, 'electron-plugin', 'dist', 'index.js')
            }
        },
        plugins: [externalizeDepsPlugin()]
    },
    preload: {
        build: {
            rollupOptions: {
                input: {
                    index: join(electronRoot, 'electron-plugin', 'dist', 'preload', 'index.mjs')
                }
            }
        },
        plugins: [externalizeDepsPlugin()]
    },
    renderer: {}
});
