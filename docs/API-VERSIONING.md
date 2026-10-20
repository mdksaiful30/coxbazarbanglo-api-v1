# API versioning and production deployment

Application release: 1.2.0. API contract: v1. Existing /api/v1 endpoints remain compatible.
GET https://coxbazarbanglo.learnwithzaman.com/api/v1/version is public and returns application_version, api_version and api_base_path inside data. It reveals no credentials, users, PHP or framework versions.

Changed files: config/release.php; app/Http/Controllers/ApiVersionController.php; routes/api.php; tests/Feature/ApiVersionTest.php; tools/generate-api-docs.py; docs/api-schema-base.json; regenerated docs/openapi.json, docs/postman.json and docs/API-COVERAGE.md; this guide.

Deploy these Laravel source/docs changes to Hostinger. Keep production .env and APP_KEY. Run php artisan optimize:clear then php artisan config:cache and php artisan route:cache. No database migration is required for this release. Verify /api/v1/version returns JSON and /api/v1/auth/me returns JSON 401 without a token. Updating local code does not deploy the server.

Postman defaults to production: mutations change real data. Change base_url to http://127.0.0.1:8013 for development. Enter your own credentials; none are embedded.

For compatible changes keep v1 and increment application_version. For breaking request/response changes add a separate /api/v2 group, retain v1 during mobile migration, and explicitly configure a new mobile release for v2. Do not rename v1 in place. Regenerate documentation with python tools/generate-api-docs.py and run php artisan test.

Google Play requires Android versionName/versionCode, separately from Laravel or API versions. Flutter 1.0.0+1 means versionName 1.0.0 and versionCode 1. Increment the number after + for every subsequent Play upload.

The docs/API.md overview was also updated with a link to this guide.
