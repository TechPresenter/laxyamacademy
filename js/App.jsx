import { Component, lazy, Suspense } from 'react';
import { BrowserRouter, Link, Route, Routes, useLocation } from 'react-router-dom';
import { APP_BASE } from './api.js';
import { AuthProvider, RequireAuth } from './auth.jsx';
import { Icon, SiteProvider, Spinner } from './components.jsx';
import { Layout } from './layout.jsx';
import Home from '../pages/Home.jsx';

const STALE_CHUNK = /Loading chunk|dynamically imported module|Importing a module script failed|error loading dynamically/i;

/** Keeps the header/footer usable when one page crashes; after a deploy, reloads once for missing chunks. */
class PageErrorBoundary extends Component {
  state = { error: null };

  static getDerivedStateFromError(error) {
    return { error };
  }

  componentDidCatch(error) {
    if (!STALE_CHUNK.test(String(error?.message || ''))) return;
    try {
      if (sessionStorage.getItem('lx-chunk-reload') === window.location.pathname) return;
      sessionStorage.setItem('lx-chunk-reload', window.location.pathname);
    } catch { /* storage unavailable: still try one reload */ }
    window.location.reload();
  }

  render() {
    if (!this.state.error) return this.props.children;
    return (
      <section className="container-x grid min-h-[55vh] place-items-center py-16 text-center">
        <div className="card max-w-md p-8">
          <span className="mx-auto grid size-14 place-items-center rounded-2xl bg-red-50 text-danger"><Icon name="refresh" className="size-7" /></span>
          <h1 className="mt-5 text-2xl">Something went wrong</h1>
          <p className="mt-2 text-sm text-muted">This page could not be displayed. Please try again - if it keeps happening, contact us and we will fix it quickly.</p>
          <div className="mt-6 flex flex-wrap justify-center gap-3">
            <button type="button" onClick={() => this.setState({ error: null })} className="btn-primary">Try again</button>
            <Link to="/" onClick={() => this.setState({ error: null })} className="btn-light">Go to homepage</Link>
          </div>
        </div>
      </section>
    );
  }
}

/** A fresh boundary per URL, so navigating away from a crashed page recovers. */
function RouteBoundary({ children }) {
  const { pathname } = useLocation();
  return <PageErrorBoundary key={pathname}>{children}</PageErrorBoundary>;
}

// Route-level code splitting: only the homepage ships in the initial bundle.
const page = (loader, name = 'default') => {
  const Lazy = lazy(() => loader().then((m) => ({ default: m[name] })));
  return function Page(props) {
    return <RouteBoundary><Lazy {...props} /></RouteBoundary>;
  };
};
const Courses = page(() => import('../pages/Courses.jsx'));
const CourseDetails = page(() => import('../pages/CourseDetails.jsx'));
const Categories = page(() => import('../pages/Categories.jsx'));
const MockTests = page(() => import('../pages/MockTests.jsx'));
const MockTestDetails = page(() => import('../pages/MockTestDetails.jsx'));
const ExamRoom = page(() => import('../pages/MockTestDetails.jsx'), 'ExamRoom');
const ExamResult = page(() => import('../pages/MockTestDetails.jsx'), 'ExamResult');
const LiveClasses = page(() => import('../pages/LiveClasses.jsx'));
const Resources = page(() => import('../pages/Resources.jsx'));
const ResourceDetails = page(() => import('../pages/Resources.jsx'), 'ResourceDetails');
const Blog = page(() => import('../pages/Blog.jsx'));
const BlogDetails = page(() => import('../pages/BlogDetails.jsx'));
const About = page(() => import('../pages/About.jsx'));
const Policy = page(() => import('../pages/Policy.jsx'));
const Contact = page(() => import('../pages/Contact.jsx'));
const Jobs = page(() => import('../pages/Jobs.jsx'));
const JobDetails = page(() => import('../pages/JobDetails.jsx'));
const Enrolled = page(() => import('../pages/Enrolled.jsx'));
const Careers = page(() => import('../pages/Careers.jsx'));
const CareerDetails = page(() => import('../pages/CareerDetails.jsx'));
const Faq = page(() => import('../pages/Faq.jsx'));
const Gallery = page(() => import('../pages/Gallery.jsx'));
const GalleryAlbum = page(() => import('../pages/GalleryAlbum.jsx'));
const CustomPage = page(() => import('../pages/CustomPage.jsx'));
const Login = page(() => import('../pages/Login.jsx'));
const Register = page(() => import('../pages/Register.jsx'));
const ForgotPassword = page(() => import('../pages/ForgotPassword.jsx'));
const ResetPassword = page(() => import('../pages/ResetPassword.jsx'));
const VerifyEmail = page(() => import('../pages/VerifyEmail.jsx'));
const StudentDashboard = page(() => import('../pages/StudentDashboard.jsx'));
const DashboardOverview = page(() => import('../pages/StudentDashboard.jsx'), 'DashboardOverview');
const MyCourses = page(() => import('../pages/MyCourses.jsx'));
const LessonPlayer = page(() => import('../pages/LessonPlayer.jsx'));
const MyTests = page(() => import('../pages/MyTests.jsx'));
const MyLiveClasses = page(() => import('../pages/MyLiveClasses.jsx'));
const MyResources = page(() => import('../pages/MyResources.jsx'));
const MyPurchases = page(() => import('../pages/MyPurchases.jsx'));
const MyProfile = page(() => import('../pages/MyProfile.jsx'));
const Notifications = page(() => import('../pages/Notifications.jsx'));
const MyCertificates = page(() => import('../pages/MyCertificates.jsx'));
const Wishlist = page(() => import('../pages/Wishlist.jsx'));
const Settings = page(() => import('../pages/Settings.jsx'));
const Support = page(() => import('../pages/Support.jsx'));
const MyDoubts = page(() => import('../pages/MyDoubts.jsx'));
const ReferEarn = page(() => import('../pages/ReferEarn.jsx'));
const Checkout = page(() => import('../pages/Checkout.jsx'));
const PaymentResult = page(() => import('../pages/PaymentResult.jsx'));
const NotFound = page(() => import('../pages/NotFound.jsx'));

const auth = (el) => <RequireAuth>{el}</RequireAuth>;

export default function App() {
  return (
    <BrowserRouter basename={APP_BASE.replace(/\/$/, '') || '/'}>
      <AuthProvider>
        <SiteProvider>
          <Suspense fallback={<Spinner />}>
            <Routes>
              <Route element={<Layout />}>
                <Route index element={<RouteBoundary><Home /></RouteBoundary>} />
                <Route path="courses" element={<Courses />} />
                <Route path="courses/:slug" element={<CourseDetails />} />
                <Route path="categories" element={<Categories />} />
                <Route path="mock-tests" element={<MockTests />} />
                <Route path="mock-tests/:slug" element={<MockTestDetails />} />
                <Route path="mock-tests/:slug/attempt/:attemptId" element={auth(<ExamRoom />)} />
                <Route path="mock-tests/:slug/result/:attemptId" element={auth(<ExamResult />)} />
                <Route path="live-classes" element={<LiveClasses />} />
                <Route path="resources" element={<Resources />} />
                <Route path="resources/:slug" element={<ResourceDetails />} />
                <Route path="blog" element={<Blog />} />
                <Route path="blog/:slug" element={<BlogDetails />} />
                <Route path="about" element={<About />} />
                <Route path="contact" element={<Contact />} />
                <Route path="privacy-policy" element={<Policy pageKey="privacy" />} />
                <Route path="terms" element={<Policy pageKey="terms" />} />
                <Route path="refund-policy" element={<Policy pageKey="refund" />} />
                <Route path="cancellation-policy" element={<Policy pageKey="cancellation" />} />
                <Route path="delivery-policy" element={<Policy pageKey="delivery" />} />
                <Route path="disclaimer" element={<Policy pageKey="disclaimer" />} />
                <Route path="cookie-policy" element={<Policy pageKey="cookies" />} />
                <Route path="jobs" element={<Jobs />} />
                <Route path="jobs/:slug" element={<JobDetails />} />
                <Route path="careers" element={<Careers />} />
                <Route path="careers/:slug" element={<CareerDetails />} />
                <Route path="faq" element={<Faq />} />
                <Route path="gallery" element={<Gallery />} />
                <Route path="gallery/:slug" element={<GalleryAlbum />} />
                <Route path="p/:slug" element={<CustomPage />} />

                <Route path="login" element={<Login />} />
                <Route path="register" element={<Register />} />
                <Route path="forgot-password" element={<ForgotPassword />} />
                <Route path="reset-password" element={<ResetPassword />} />
                <Route path="verify-email" element={<VerifyEmail />} />

                <Route path="dashboard" element={auth(<StudentDashboard />)}>
                  <Route index element={<DashboardOverview />} />
                  <Route path="courses" element={<MyCourses />} />
                  <Route path="tests" element={<MyTests />} />
                  <Route path="live-classes" element={<MyLiveClasses />} />
                  <Route path="resources" element={<MyResources />} />
                  <Route path="purchases" element={<MyPurchases />} />
                  <Route path="notifications" element={<Notifications />} />
                  <Route path="profile" element={<MyProfile />} />
                  <Route path="certificates" element={<MyCertificates />} />
                  <Route path="wishlist" element={<Wishlist />} />
                  <Route path="settings" element={<Settings />} />
                  <Route path="support" element={<Support />} />
                  <Route path="doubts" element={<MyDoubts />} />
                  <Route path="doubts/:id" element={<MyDoubts />} />
                  <Route path="refer" element={<ReferEarn />} />
                </Route>
                {/* Guests may open free-preview lessons; the API enforces access for everything else. */}
                <Route path="learn/:slug" element={<LessonPlayer />} />
                <Route path="learn/:slug/:lessonId" element={<LessonPlayer />} />
                <Route path="enrolled/:slug" element={auth(<Enrolled />)} />
                <Route path="checkout/:slug" element={auth(<Checkout />)} />
                <Route path="payment/result" element={auth(<PaymentResult />)} />

                <Route path="*" element={<NotFound />} />
              </Route>
            </Routes>
          </Suspense>
        </SiteProvider>
      </AuthProvider>
    </BrowserRouter>
  );
}
