<div class="footer">
    <hr class="hr">
    <div class="center muted">
        @if($extra)<div>{{ $extra }}</div>@endif
        <div>Generated {{ now()->format('d/m/Y, H:i:s') }}</div>
        @if($poweredBy ?? false)<div>Powered by Vellix Global</div>@endif
    </div>
</div>
