<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Something went wrong — TSMS</title>
    <style>
        html,body{height:100%;margin:0;font-family:Inter, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;color:#222;background:#f7f8fa}
        .wrap{min-height:100%;display:flex;align-items:center;justify-content:center;padding:32px}
        .card{max-width:760px;width:100%;background:#fff;border-radius:10px;box-shadow:0 6px 30px rgba(29,33,41,0.08);padding:28px}
        h1{margin:0 0 8px;font-size:20px;color:#111}
        p{margin:0 0 16px;color:#444}
        .muted{color:#6b7280;font-size:13px}
        .meta{display:flex;align-items:center;gap:12px;margin-top:16px}
        .ref{background:#f3f4f6;border-radius:6px;padding:8px 10px;font-family:monospace;color:#111}
        .actions{margin-left:auto;display:flex;gap:8px}
        .btn{padding:8px 12px;border-radius:6px;border:0;cursor:pointer}
        .btn-primary{background:#0ea5a4;color:#fff}
        .btn-ghost{background:transparent;border:1px solid #e6e7eb;color:#111}
        small.note{display:block;margin-top:10px;color:#666}
        footer{margin-top:18px;color:#8b8f98;font-size:13px}
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card" role="alert">
            <h1>Something went wrong</h1>
            <p class="muted">We couldn't complete your request right now. This is not your fault — our system logged the problem and you can try again in a moment.</p>

            <div class="meta">
                <div>
                    <div class="muted">Reference</div>
                    <div class="ref" id="refId">{{ $reference_id ?? request()->header('X-Request-ID') ?? 'n/a' }}</div>
                    <small class="note">Please copy and share this reference when contacting support.</small>
                </div>

                <div class="actions">
                    <button class="btn btn-primary" onclick="window.location.reload()">Retry</button>
                    <button class="btn btn-ghost" id="copyBtn">Copy</button>
                    <a class="btn btn-ghost" href="mailto:support@pitx.com.ph?subject=TSMS%20Error%20Report&body=Reference%20ID:%20{{ $reference_id ?? request()->header('X-Request-ID') ?? '' }}" role="button">Contact Support</a>
                </div>
            </div>

            <footer>
                If the problem persists, please contact your administrator or support. We log the technical details and will investigate with the reference id above.
            </footer>
        </div>
    </div>

    <script>
        (function(){
            var copyBtn = document.getElementById('copyBtn');
            var refEl = document.getElementById('refId');
            copyBtn && copyBtn.addEventListener('click', function(){
                var text = refEl && refEl.textContent ? refEl.textContent.trim() : '';
                if(!text) return;
                try{ navigator.clipboard.writeText(text); copyBtn.textContent = 'Copied'; setTimeout(()=>copyBtn.textContent='Copy',1500); } catch(e){ alert('Reference copied: ' + text); }
            });
        })();
    </script>
</body>
</html>
