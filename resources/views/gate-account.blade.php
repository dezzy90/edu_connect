<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Activate your EduConnect setup account</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f1f5f9;color:#0f172a;font:16px/1.6 system-ui,sans-serif}main{max-width:34rem;margin:8vh auto;padding:2rem;background:#fff;border:1px solid #cbd5e1;border-radius:1rem}h1{font-size:1.65rem;line-height:1.3}label{display:block;margin-top:1.25rem;font-weight:600}input{display:block;width:100%;padding:.8rem;border:1px solid #64748b;border-radius:.4rem;font:inherit}button,.link{display:inline-block;margin-top:1.5rem;padding:.8rem 1.2rem;background:#0f172a;color:white;border:0;border-radius:.4rem;font:inherit}a{color:#075985}button{cursor:pointer}input:focus,a:focus,button:focus{outline:3px solid #0284c7;outline-offset:3px}.error{color:#991b1b}small{display:block;color:#475569}@media(max-width:600px){main{margin:1rem;padding:1.4rem}}
    </style>
</head>
<body><main>
    <p>EduConnect · School setup</p>
    @if ($completed)
        <h1>Your setup account is active</h1>
        <p>Your password has been saved securely. This link cannot be used again.</p>
        <p>Activating your account does not enable terminal enrollment, attendance processing or parent messages.</p>
        <a class="link" href="/admin/login">Sign in to school setup</a>
    @elseif ($invitation)
        <h1>Choose your password</h1>
        <p>This activates the restricted setup account for <strong>{{ $invitation['email'] }}</strong>. It does not grant global administration or discipline permissions.</p>
        <small>Link expires {{ gmdate('Y-m-d H:i', $invitation['expires_at']) }} UTC. Never send your password in chat.</small>
        @if ($errors->any())
            <div role="alert" class="error"><p>Please check your password:</p><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        <form method="post">
            @csrf
            <label for="password">New password</label>
            <input id="password" name="password" type="password" autocomplete="new-password" required minlength="12" maxlength="128" aria-describedby="requirements">
            <small id="requirements">At least 12 characters, with uppercase, lowercase, a number and a symbol.</small>
            <label for="password_confirmation">Confirm password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required minlength="12" maxlength="128">
            <button type="submit">Activate my setup account</button>
        </form>
    @else
        <h1>This activation link is unavailable</h1>
        <p>It may have expired, been replaced or already been used. Ask your installation administrator for a new link.</p>
        <a href="/admin/login">Return to sign in</a>
    @endif
</main></body>
</html>
