@unless (app()->isProduction())
    <span style="background:#FEF3C7;color:#92400E;border-radius:9999px;padding:2px 10px;font-size:11px;font-weight:600;letter-spacing:.04em;text-transform:uppercase">{{ app()->environment() }}</span>
@endunless
