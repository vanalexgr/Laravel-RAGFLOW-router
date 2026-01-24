# Local Testing Guide - Abbreviation System

## Prerequisites

Ensure you have:
- PHP 8.2+ installed locally
- Composer dependencies installed
- Laravel app key generated

```bash
# Check PHP version
php -v

# Install dependencies if needed
composer install

# Generate app key if .env doesn't exist
cp .env.example .env
php artisan key:generate
```

---

## Testing Workflow

### Step 1: Pre-Commit Validation

Run the standalone parser test to validate without Laravel:

```bash
php scripts/test_abbreviation_parser.php
```

**Expected Output**:
- Parses all 3 MD files successfully
- Shows sample abbreviations from each
- Reports any parsing errors

---

### Step 2: Import Abbreviations

```bash
# Import PAD guideline
php artisan guidelines:import-abbr storage/PAD.md --guideline=asymptomatic_pad --dry-run

# If dry-run looks good, import for real
php artisan guidelines:import-abbr storage/PAD.md --guideline=asymptomatic_pad

# Import VGEI guideline
php artisan guidelines:import-abbr storage/infections.md --guideline=vascular_graft_infections

# Import Vascular Access guideline
php artisan guidelines:import-abbr storage/vascular_access.md --guideline=vascular_access
```

**Validation**: Check that `storage/app/guidelines/abbr/normalized/*.json` files are created with valid JSON.

---

### Step 3: Verify Statistics

```bash
php artisan guidelines:abbr-stats
```

**Expected**:
- Total abbreviations: ~150-200
- Guidelines loaded: 3
- Conflicts detected: 5-15 (normal)

---

### Step 4: Test Query Expansion

**Critical Test: TEVAR Infection** (your main use case)

```bash
php artisan guidelines:test-expansion "6 months after TEVAR persistent fever raised CRP peri-graft fluid on CTA"
```

**Must Detect**:
- `TEVAR` → thoracic endovascular aortic repair
- `CRP` → C-reactive protein (or C reactive protein)
- `CTA` → Computed tomography angiography

**Additional Tests**:

```bash
# Test fistula abbreviation
php artisan guidelines:test-expansion "AESF after TEVAR procedure"

# Test mixed case
php artisan guidelines:test-expansion "Patient with AEsf and MRSA"

# Test vascular access
php artisan guidelines:test-expansion "AVF with stenosis HD access"
```

---

### Step 5: Cache Testing

```bash
# Clear cache
php artisan guidelines:clear-abbr-cache

# Re-import and verify cache works
php artisan guidelines:abbr-stats
```

---

## Git Workflow for Production

### Before Committing

1. **Verify all tests pass** (steps above)
2. **Check no sensitive data** in normalized JSON files
3. **Review changes**:

```bash
git status
git diff
```

---

### Commit Changes

```bash
# Stage new files
git add app/Services/Routing/
git add app/Console/Commands/ImportAbbreviationsCommand.php
git add app/Console/Commands/AbbreviationStatsCommand.php
git add app/Console/Commands/TestExpansionCommand.php
git add app/Console/Commands/ClearAbbreviationCacheCommand.php
git add config/router_abbreviations.php

# Stage storage structure (gitignore will handle data)
git add -f storage/app/guidelines/.gitkeep

# Optional: Add test script
git add scripts/test_abbreviation_parser.php

# Commit
git commit -m "feat: Add abbreviation-aware routing system (Phase 1)

- Add AbbreviationStore service with caching and conflict detection
- Add QueryExpander with 3 expansion formats (append/inline/dual)
- Add MarkdownAbbreviationParser for 3 MD table formats
- Add 4 Artisan commands for import/stats/testing/cache
- Add router_abbreviations config with guardrail keywords
- Prepare for integration with GuidelineRouterService"

# Push to GitHub
git push origin main
```

---

### Deployment to Azure VM

**SSH into VM**:

```bash
ssh user@your-azure-vm-ip
cd /path/to/laravel-ragflow-router
```

**Pull and Deploy**:

```bash
# Pull changes
git pull origin main

# Install/update dependencies (if composer.json changed)
composer install --no-dev --optimize-autoloader

# Clear Laravel caches
php artisan config:clear
php artisan cache:clear

# Import abbreviations on production
php artisan guidelines:import-abbr storage/PAD.md --guideline=asymptomatic_pad
php artisan guidelines:import-abbr storage/infections.md --guideline=vascular_graft_infections
php artisan guidelines:import-abbr storage/vascular_access.md --guideline=vascular_access

# Verify
php artisan guidelines:abbr-stats

# Test expansion
php artisan guidelines:test-expansion "TEVAR infection CRP"

# Restart services (if using queue workers)
php artisan queue:restart
```

---

## Rollback Plan

If issues occur in production:

```bash
# Disable abbreviation expansion
php artisan config:clear
# Edit .env: ABBREVIATION_EXPANSION_ENABLED=false
php artisan config:cache

# OR rollback git
git revert HEAD
composer install --no-dev
php artisan config:clear
php artisan cache:clear
```

---

## Storage Gitignore

Add to `.gitignore` to avoid committing user data:

```gitignore
# Abbreviation data files
storage/app/guidelines/abbr/normalized/*.json
storage/app/guidelines/abbr/raw/*

# But keep directory structure
!storage/app/guidelines/abbr/.gitkeep
```

Create `.gitkeep` files:

```bash
touch storage/app/guidelines/abbr/.gitkeep
touch storage/app/guidelines/abbr/raw/.gitkeep
touch storage/app/guidelines/abbr/normalized/.gitkeep
```

---

## Troubleshooting

**Issue: Class not found**

```bash
composer dump-autoload
```

**Issue: Config cached**

```bash
php artisan config:clear
php artisan cache:clear
```

**Issue: Permission denied on storage**

```bash
chmod -R 775 storage
chown -R www-data:www-data storage  # On production
```

**Issue: Parser not detecting acronyms**

Check regex patterns in `config/router_abbreviations.php` and test with:

```bash
php artisan guidelines:test-expansion "TEST ABBR HERE" -v
```
