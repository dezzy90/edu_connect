import { useMutation } from '@tanstack/react-query';
import { Eye, EyeOff, Loader2, Lock, Mail, ShieldCheck } from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router';
import { authApi, errorMessage } from '../lib/api';
import { useAuthStore } from '../store/authStore';

export default function LoginPage() {
  const navigate = useNavigate();
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated);
  const setAuth = useAuthStore((state) => state.setAuth);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [remember, setRemember] = useState(true);
  const [showPassword, setShowPassword] = useState(false);

  useEffect(() => {
    if (isAuthenticated) {
      navigate('/dashboard', { replace: true });
    }
  }, [isAuthenticated, navigate]);

  const loginMutation = useMutation({
    mutationFn: authApi.login,
    onSuccess: (data) => {
      setAuth(data.admin, data.access_token, data.expires_at);
      navigate('/dashboard', { replace: true });
    },
  });

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    loginMutation.mutate({
      email,
      password,
      remember,
      device_name: 'Edu-connect web admin',
    });
  }

  return (
    <div className="login-screen">
      <main className="login-panel">
        <section className="login-copy">
          <div className="brand-lockup">
            <div className="brand-mark large">
              <ShieldCheck size={28} />
            </div>
            <div>
              <strong>Edu Connect</strong>
              <span>School communication control</span>
            </div>
          </div>
          <h1>Coordinate attendance, parent communication, and Edu-admin sync from one focused console.</h1>
          <p>
            This panel is designed for the Edu-connect backend while the mobile app stays fast and simple for parents.
          </p>
          <div className="login-points">
            <span>Realtime conversations</span>
            <span>Push notification readiness</span>
            <span>Edu-admin integration health</span>
          </div>
        </section>

        <form className="login-form" onSubmit={handleSubmit}>
          <div>
            <p className="eyebrow">Admin access</p>
            <h2>Welcome back</h2>
            <p className="form-subtitle">Sign in with your Edu-connect administrator account.</p>
          </div>

          {loginMutation.isError && (
            <div className="form-alert">{errorMessage(loginMutation.error, 'Unable to sign in with those credentials.')}</div>
          )}

          <label className="field">
            <span>Email address</span>
            <div className="input-with-icon">
              <Mail size={18} />
              <input
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                type="email"
                autoComplete="email"
                placeholder="admin@example.com"
                required
              />
            </div>
          </label>

          <label className="field">
            <span>Password</span>
            <div className="input-with-icon">
              <Lock size={18} />
              <input
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                type={showPassword ? 'text' : 'password'}
                autoComplete="current-password"
                placeholder="Enter password"
                required
              />
              <button
                className="inline-icon-button"
                type="button"
                onClick={() => setShowPassword((visible) => !visible)}
                aria-label={showPassword ? 'Hide password' : 'Show password'}
              >
                {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
              </button>
            </div>
          </label>

          <label className="check-row">
            <input type="checkbox" checked={remember} onChange={(event) => setRemember(event.target.checked)} />
            <span>Keep me signed in on this device</span>
          </label>

          <button className="primary-button" type="submit" disabled={loginMutation.isPending}>
            {loginMutation.isPending ? <Loader2 size={20} className="spin" /> : <ShieldCheck size={20} />}
            Sign in
          </button>
        </form>
      </main>
    </div>
  );
}
