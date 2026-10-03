{{-- Config page layout. Plain CSS, because Tailwind doesn't scan plugin views. --}}
<style>
    .us-config-group { margin-bottom: 2.5rem; }
    .us-config-group:last-child { margin-bottom: 0; }
    .us-config-group .fi-section-header-heading { font-size: 1.05rem; font-weight: 600; }
    .us-config-group .fi-fo-field.fi-fo-field-has-inline-label { grid-template-columns: minmax(0, 3fr) minmax(0, 2fr); column-gap: 2rem; }
    .us-config-group .fi-fo-field.fi-fo-field-has-inline-label .fi-fo-field-content-col { grid-column: auto; }
    .us-config-group .fi-fo-field { padding: 0.25rem 0; }
    .us-config-title { display: block; font-weight: 500; }
    .us-config-help { display: block; margin-top: 0.15rem; font-size: 0.8rem; font-weight: 400; color: rgb(107 114 128); }
    .dark .us-config-help { color: rgb(161 161 170); }
    /* The restart note beside the save buttons reads as text, not a disabled button. */
    .us-config-note { opacity: 1 !important; cursor: default; padding: 0.35rem 0.75rem; border-radius: 9999px; background: rgb(217 119 6 / 0.1); }
</style>
