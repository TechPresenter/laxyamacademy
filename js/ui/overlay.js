// Shared behaviour for the site's overlays (drawer, search, popups, dropdown menus):
// body scroll lock, focus trap with Escape (top-most overlay only) and small media helpers.
import { useEffect, useLayoutEffect, useRef, useState } from 'react';

// ------------------------------------------------------------------
// Scroll lock (reference counted so stacked overlays unlock correctly)
// ------------------------------------------------------------------
let locks = 0;

/** True while any modal overlay (drawer, search, popup) is open. Used to defer popups. */
export const overlayOpen = () => locks > 0;

export function useScrollLock(active = true) {
  useLayoutEffect(() => {
    if (!active) return undefined;
    const body = document.body;
    if (locks++ === 0) {
      // Keep the layout from jumping when the desktop scrollbar disappears
      const gap = window.innerWidth - document.documentElement.clientWidth;
      body.style.overflow = 'hidden';
      if (gap > 0) body.style.paddingRight = `${gap}px`;
      body.dataset.overlay = 'open';
    }
    return () => {
      if (--locks === 0) {
        body.style.overflow = '';
        body.style.paddingRight = '';
        delete body.dataset.overlay;
      }
    };
  }, [active]);
}

// ------------------------------------------------------------------
// Focus trap + Escape
// ------------------------------------------------------------------
const stack = []; // open traps, last = top-most
const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

const focusables = (node) => [...node.querySelectorAll(FOCUSABLE)].filter((el) => el.offsetParent !== null || el.getClientRects().length > 0);

/**
 * Keeps keyboard focus inside `ref` while active, closes on Escape (only the top-most
 * overlay reacts) and returns focus to the element that opened it.
 * initialFocus: a ref, a CSS selector inside the node, or omitted (first focusable).
 */
export function useFocusTrap(ref, active = true, { initialFocus, onEscape, restoreFocus = true } = {}) {
  const escRef = useRef(onEscape);
  escRef.current = onEscape;

  useEffect(() => {
    const node = ref.current;
    if (!active || !node) return undefined;
    const token = {};
    stack.push(token);
    const previous = document.activeElement;

    // Next task rather than next frame: also works while the tab is in the background
    const frame = setTimeout(() => {
      let target = null;
      if (typeof initialFocus === 'string') target = node.querySelector(initialFocus);
      else if (initialFocus?.current) target = initialFocus.current;
      (target || focusables(node)[0] || node).focus({ preventScroll: true });
    }, 0);

    const onKey = (e) => {
      if (stack[stack.length - 1] !== token) return;
      if (e.key === 'Escape') {
        e.preventDefault();
        escRef.current?.();
        return;
      }
      if (e.key !== 'Tab') return;
      const els = focusables(node);
      if (!els.length) {
        e.preventDefault();
        return;
      }
      const first = els[0];
      const last = els[els.length - 1];
      if (!node.contains(document.activeElement)) {
        e.preventDefault();
        (e.shiftKey ? last : first).focus();
      } else if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    };
    document.addEventListener('keydown', onKey);

    return () => {
      clearTimeout(frame);
      document.removeEventListener('keydown', onKey);
      const i = stack.indexOf(token);
      if (i >= 0) stack.splice(i, 1);
      if (restoreFocus && previous && typeof previous.focus === 'function' && document.contains(previous)) {
        previous.focus({ preventScroll: true });
      }
    };
  }, [active]); // eslint-disable-line react-hooks/exhaustive-deps
}

// ------------------------------------------------------------------
// Media query hook
// ------------------------------------------------------------------
export function useMediaQuery(query) {
  const get = () => (typeof window !== 'undefined' && window.matchMedia ? window.matchMedia(query).matches : false);
  const [matches, setMatches] = useState(get);
  useEffect(() => {
    if (!window.matchMedia) return undefined;
    const mq = window.matchMedia(query);
    const on = () => setMatches(mq.matches);
    on();
    mq.addEventListener?.('change', on);
    return () => mq.removeEventListener?.('change', on);
  }, [query]);
  return matches;
}

/** True when the keyboard event target is a text field (so "/" should type, not open search). */
export const isTypingTarget = (el) => !!el && (el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName));
