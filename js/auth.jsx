// Authentication state for React. The PHP session decides who the user is;
// this context only mirrors GET auth.php?action=me so the UI can react.
import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { Navigate, useLocation, useNavigate } from 'react-router-dom';
import { api, APP_BASE, resetCsrf } from './api.js';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);

  const refresh = useCallback(async () => {
    try {
      const data = await api.get('auth.php', { action: 'me' });
      setUser(data.user);
    } catch {
      setUser(null);
    } finally {
      setLoading(false);
    }
  }, []);

  // Login/register rotate the session and CSRF token on the server.
  const login = useCallback(async (email, password) => {
    const data = await api.post('auth.php', { email, password }, { action: 'login' });
    resetCsrf(data.csrf);
    setUser(data.user);
    return data;
  }, []);

  const register = useCallback(async (payload) => {
    const data = await api.post('auth.php', payload, { action: 'register' });
    resetCsrf(data.csrf);
    setUser(data.user);
    return data;
  }, []);

  // An explicit logout remembers the page it happened on and where the user should land
  // (home by default). Without it, RequireAuth on a protected page would react to user=null
  // with its own /login?next=… redirect, which wins over the caller's navigate('/').
  const { pathname } = useLocation();
  const [signedOut, setSignedOut] = useState(null); // { from: pathname, to: path } | null
  const logout = useCallback(async (to = '/') => {
    // (`to` may be a click event when logout is used directly as an onClick handler)
    setSignedOut({ from: pathname, to: typeof to === 'string' ? safeNext(to, '/') : '/' });
    try {
      await api.post('auth.php', {}, { action: 'logout' });
    } finally {
      resetCsrf();
      setUser(null);
    }
  }, [pathname]);
  // One-shot: forget the flag as soon as the user leaves the page they logged out on.
  useEffect(() => {
    if (signedOut && pathname !== signedOut.from) setSignedOut(null);
  }, [pathname, signedOut]);

  useEffect(() => {
    refresh();
  }, [refresh]);

  // Student summary: unread notifications + wishlist (header bell, heart buttons)
  const [summary, setSummary] = useState({ unread_notifications: 0, wishlist: [], open_tickets: 0 });
  const refreshSummary = useCallback(async () => {
    try {
      setSummary(await api.get('students.php', { action: 'summary' }));
    } catch {
      /* not a student or logged out */
    }
  }, []);
  useEffect(() => {
    if (user?.type === 'student') refreshSummary();
    else setSummary({ unread_notifications: 0, wishlist: [], open_tickets: 0 });
  }, [user, refreshSummary]);

  const toggleWishlist = useCallback(async (course, on) => {
    setSummary((s) => ({ ...s, wishlist: on ? [...s.wishlist, course.id] : s.wishlist.filter((id) => id !== course.id) }));
    try {
      await api.post('students.php', { slug: course.slug, on }, { action: 'wishlist' });
    } catch {
      refreshSummary();
    }
  }, [refreshSummary]);

  const value = useMemo(
    () => ({ user, loading, refresh, login, register, logout, setUser, summary, refreshSummary, toggleWishlist, signedOut }),
    [user, loading, refresh, login, register, logout, summary, refreshSummary, toggleWishlist, signedOut]
  );
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used inside <AuthProvider>');
  return ctx;
}

/** Route guard for student pages. The server enforces access too - this only redirects. */
export function RequireAuth({ children }) {
  const { user, loading, signedOut } = useAuth();
  const location = useLocation();
  // Just logged out on this page: go where the logout asked (home), not to /login?next=…
  if (!user && signedOut && signedOut.from === location.pathname) {
    return <Navigate to={signedOut.to} replace />;
  }
  if (loading) {
    return (
      <div role="status" className="flex justify-center py-24">
        <span className="size-7 animate-spin rounded-full border-2 border-primary border-t-transparent" />
      </div>
    );
  }
  if (!user) {
    return <Navigate to={`/login?next=${encodeURIComponent(location.pathname + location.search)}`} replace />;
  }
  // Staff share the PHP session with /admin; the student dashboard is not for them (the API refuses it too)
  if (user.type === 'staff' && /^\/dashboard(\/|$)/.test(location.pathname)) {
    return <StaffNotice user={user} />;
  }
  return children;
}

function StaffNotice({ user }) {
  const { logout } = useAuth();
  const navigate = useNavigate();
  return (
    <section className="bg-mesh">
      <div className="container-x grid min-h-[60vh] place-items-center py-16">
        <div className="card max-w-lg p-8 text-center sm:p-10">
          <span className="mx-auto grid size-14 place-items-center rounded-2xl bg-primary-soft font-display text-xl font-extrabold text-primary" aria-hidden="true">
            {(user.name || 'S').charAt(0).toUpperCase()}
          </span>
          <h1 className="heading-lg mt-5">You are signed in as staff</h1>
          <p className="mt-3 text-muted">
            {user.name} is a staff account. The student dashboard is only for learners - manage courses, students and orders from the admin panel.
          </p>
          <div className="mt-7 flex flex-wrap justify-center gap-3">
            <a href={APP_BASE + 'admin/'} className="btn-primary">Open admin panel</a>
            <button type="button" onClick={async () => { await logout('/login'); navigate('/login'); }} className="btn-light">Log out</button>
          </div>
        </div>
      </div>
    </section>
  );
}

/** Only allow same-site relative redirects after login (prevents open redirects). */
export function safeNext(next, fallback = '/dashboard') {
  return next && next.startsWith('/') && !next.startsWith('//') ? next : fallback;
}
