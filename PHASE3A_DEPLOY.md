# Phase 3A Deployment Guide - Enhanced Guardrails

## 🎉 Implementation Complete!

Enhanced guardrail system with 5 critical rules is ready for production testing.

---

## 📦 What Was Built

### **1. Enhanced Configuration**
[`config/router_abbreviations.php`](file:///home/vga/LAVAREL/Laravel-RAGFLOW-router/config/router_abbreviations.php)
- 4 action types: PIN, FORCE_INCLUDE, EXCLUDE, COMPANION
- Priority ordering for all 14 guidelines (5 implemented)
- Score gap threshold: 0.08 (8%)
- 5 critical guardrail rules with collision detection

### **2. Enhanced GuardrailDecider**
[`app/Services/Routing/GuardrailDecider.php`](file:///home/vga/LAVAREL/Laravel-RAGFLOW-router/app/Services/Routing/GuardrailDecider.php)
- Supports all 4 action types
- Priority-based rule evaluation
- Collision resolution with companion additions
- Score gap analysis for close candidates
- Comprehensive debug logging

### **3. Test Script**
[`tests/test_enhanced_guardrails.php`](file:///home/vga/LAVAREL/Laravel-RAGFLOW-router/tests/test_enhanced_guardrails.php)
- 5 critical test scenarios
- Expected outcome validation
- Debug output for troubleshooting

---

## 🚀 Deployment Steps (Azure VM)

### **Step 1: Commit & Push from Local**

```bash
cd ~/LAVAREL/Laravel-RAGFLOW-router

# Stage all enhanced guardrail files
git add config/router_abbreviations.php
git add app/Services/Routing/GuardrailDecider.php  
git add tests/test_enhanced_guardrails.php
git add .gemini/

# Commit
git commit -m "feat: Phase 3A - Enhanced guardrail system with 5 critical rules

- Updated config with PIN/FORCE_INCLUDE/EXCLUDE/COMPANION actions
- Rewrote GuardrailDecider with priority ordering and collision resolution
- Added 5 critical guardrails: VGEI, Trauma, ALI, Antithrombotic, AAA
- Trauma EXCLUDED by default (strict opt-in)
- VGEI collision adds territory guideline (TEVAR→Thoracic, EVAR→AAA)
- Antithrombotic as COMPANION for medication queries
- Score gap analysis keeps close candidates
- Test script for 5 critical scenarios"

# Push
git push origin main
```

### **Step 2: Pull on Azure VM**

```bash
cd /var/www/laravel-ragflow

# Pull latest code
git pull origin main

# Clear config cache
php artisan config:clear

# Verify files loaded
ls -la config/router_abbreviations.php
ls -la app/Services/Routing/GuardrailDecider.php
```

### **Step 3: Run Tests**

```bash
cd /var/www/laravel-ragflow

# Run enhanced guardrail tests
php tests/test_enhanced_guardrails.php
```

**Expected Output**: All 5 tests should PASS:
- ✅ Test 1: TEVAR Infection (VGEI + Thoracic collision)
- ✅ Test 2: AAA Rupture (Trauma excluded)
- ✅ Test 3: Trauma + ALI (both returned)
- ✅ Test 4: PAD + Antithrombotic companion
- ✅ Test 5: ALI classic presentation

### **Step 4: Test Original TEVAR Infection Query**

```bash
# Create quick test (same as Phase 2)
cat > /tmp/test_phase3a.php << 'EOF'
<?php
require '/var/www/laravel-ragflow/vendor/autoload.php';
$app = require_once '/var/www/laravel-ragflow/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$router = $app->make(\App\Services\GuidelineRouterService::class);
$result = $router->routeQuery(
    "6 months after TEVAR persistent fever raised CRP peri-graft fluid on CTA",
    3
);

echo "🎯 Selected: " . implode(', ', $result['keys'] ?? []) . "\n";
echo "🛡️  Guardrails:\n";
foreach ($result['guardrail_debug']->decisions ?? [] as $d) {
    echo "  - [{$d['action']}] {$d['reason']}\n";
}
EOF

php /tmp/test_phase3a.php
```

**Expected Output**:
```
🎯 Selected: vascular_graft_infections, descending_thoracic_aorta, aortic_arch
🛡️  Guardrails:
  - [pin] Moved vascular_graft_infections to #1
  - [collision_add] Collision detected, added descending_thoracic_aorta
```

---

## ✅ Success Criteria

- [ ] All 5 test scenarios pass
- [ ] VGEI + TEVAR collision adds Thoracic guideline
- [ ] Trauma excluded from AAA rupture query
- [ ] ALI + Trauma both returned for GSW query
- [ ] Antithrombotic added as companion for aspirin/bleeding queries
- [ ] No errors in Laravel logs

---

## 🔄 Rollback Plan

If issues occur:

```bash
# Revert to Phase 2 (simple infection guardrail)
git revert HEAD
php artisan config:clear
```

---

## 📊 Performance Expectations

- Guardrail evaluation: < 5ms
- Total routing time: < 150ms (includes expansion + semantic + guardrails)
- Zero breaking changes for existing queries

---

## 🐛 Troubleshooting

### **Issue: Guardrails not triggering**
```bash
# Check config loaded
php artisan tinker
>>> config('router_abbreviations.guardrails.graft_infections');

# Check guardrails enabled
>>> config('router_abbreviations.guardrails_enabled');
```

### **Issue: Wrong guideline selected**
```bash
# Check debug output
php artisan tinker
>>> $router = app(\App\Services\GuidelineRouterService::class);
>>> $result = $router->routeQuery("your query", 3);
>>> dd($result['guardrail_debug']);
```

### **Issue: Config not updating**
```bash
# Force cache clear
php artisan config:clear
php artisan cache:clear
sudo systemctl restart php8.3-fpm  # if using FPM
```

---

## 📈 Next Steps After Validation

1. **Expand to 9 remaining guidelines** (Carotid, CLTI, Venous, etc.)
2. **Add more collision rules** for complex scenarios
3. **Collect real usage data** to refine keyword lists
4. **A/B test** enhanced vs. simple guardrails

---

## 🎓 What Changed from Phase 2

| Aspect | Phase 2 | Phase 3A |
|--------|---------|----------|
| Actions | 2 (prefer, force_add) | 4 (PIN, FORCE_INCLUDE, EXCLUDE, COMPANION) |
| Rules | 1 (infection) | 5 (VGEI, Trauma, ALI, Antithrombotic, AAA) |
| Collision handling | None | Auto-adds companion guidelines |
| Exclusion | None | Trauma excluded by default |
| Priority | N/A | 14-guideline ranking system |
| Score analysis | None | Gap-based top-2 selection |

**Phase 3A is ~5x more sophisticated!** 🚀
