# Staging Environment

This branch is used for testing changes before merging to production (master).

## Workflow
1. Changes are pushed to `staging` branch
2. Railway auto-deploys staging service
3. Smoke tests run against staging URL
4. If tests pass → merge to `master` → production auto-deploys

## URLs
- **Production**: quebot-production.up.railway.app (branch: master)
- **Staging**: [TBD after Railway setup]
