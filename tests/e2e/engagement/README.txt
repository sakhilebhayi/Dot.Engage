Engagement end-to-end tests (real browsers against a running app).

Setup (once per run, from the app folder):
  export E2E_APP=$PWD E2E_DB=/tmp/engage-e2e.sqlite E2E_BASE=http://127.0.0.1:8782 PLAYWRIGHT_DIR=<folder with node_modules/playwright>
  rm -f $E2E_DB && touch $E2E_DB
  DB_DATABASE=$E2E_DB php artisan migrate --force && DB_DATABASE=$E2E_DB php artisan db:seed --class=EngageDemoSeeder --force
  DB_DATABASE=$E2E_DB LOG_FILE=/tmp/engage-e2e.log APP_URL=$E2E_BASE QUEUE_CONNECTION=sync MAIL_MAILER=log SESSION_DRIVER=file CACHE_STORE=file php artisan serve --port=8782 &
Run:
  node tests/e2e/engagement/negotiate-and-sign.e2e.mjs
