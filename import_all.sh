#!/bin/bash

# Navigate to project root
cd /var/www/laravel-ragflow

# Import abbreviations for each guideline
echo "🚀 Starting Batch Import of Abbreviations..."

php artisan guidelines:import-abbr "storage/abbreviations/aorto_iliac_aneurysms.md" --guideline=abdominal_aortic_aneurysm --format=md
php artisan guidelines:import-abbr "storage/abbreviations/ALI.md" --guideline=acute_limb_ischaemia --format=md
php artisan guidelines:import-abbr "storage/abbreviations/antithrombotic.md" --guideline=antithrombotic_therapy --format=md
php artisan guidelines:import-abbr "storage/abbreviations/aortic_arch.md" --guideline=aortic_arch --format=md
php artisan guidelines:import-abbr "storage/abbreviations/PAD.md" --guideline=asymptomatic_pad --format=md
php artisan guidelines:import-abbr "storage/abbreviations/carotid.md" --guideline=carotid_vertebral --format=md
php artisan guidelines:import-abbr "storage/abbreviations/venous_disease.md" --guideline=chronic_venous_disease --format=md
php artisan guidelines:import-abbr "storage/abbreviations/CLTI.md" --guideline=clti --format=md
php artisan guidelines:import-abbr "storage/abbreviations/descending thoracic.md" --guideline=descending_thoracic --format=md
php artisan guidelines:import-abbr "storage/abbreviations/mesenteric.md" --guideline=mesenteric_renal --format=md
php artisan guidelines:import-abbr "storage/abbreviations/vascular_access.md" --guideline=vascular_access --format=md
php artisan guidelines:import-abbr "storage/abbreviations/graft_infections.md" --guideline=graft_infections --format=md
php artisan guidelines:import-abbr "storage/abbreviations/vascular_trauma.md" --guideline=vascular_trauma --format=md

# Venous Thrombosis Fallback (using venous_disease list as start)
echo "ℹ️ Importing Venous Disease abbreviations for Venous Thrombosis as well..."
php artisan guidelines:import-abbr "storage/abbreviations/venous_disease.md" --guideline=venous_thrombosis --format=md

echo "✅ Import Complete! Clearing cache..."
php artisan guidelines:clear-abbr-cache
php artisan guidelines:abbr-stats
