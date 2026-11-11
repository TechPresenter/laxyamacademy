// Header dropdown menus (Courses mega, Jobs, Highlights, More, account).
// One menu open at a time. Mouse users open on hover; touch and keyboard users
// toggle with the trigger button. Escape, an outside tap, focus leaving the menu
// or any navigation (including taps on links to the current page) closes it.
import { useCallback, useEffect, useRef, useState } from 'react';
import { useLocation } from 'react-router-dom';

/** Moves focus between [data-menu-item] elements with the arrow keys. */
function arrowNav(e) {
  if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp' && e.key !== 'Home' && e.key !== 'End') return;
  const items = [...e.currentTarget.querySelectorAll('[data-menu-item]')];
  const i = items.indexOf(document.activeElement);
  if (i < 0 || !items.length) return;
  e.preventDefault();
  const next = e.key === 'Home' ? 0 : e.key === 'End' ? items.length - 1 : (i + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
  items[next].focus();
}

export function useMenuGroup() {
  const [openId, setOpenId] = useState(null);
  const roots = useRef({});
  const timer = useRef(null);
  const hoverOpenedAt = useRef(0);
  const { key } = useLocation();

  // Any navigation closes menus (location.key changes even for same-URL link taps)
  useEffect(() => setOpenId(null), [key]);
  useEffect(() => () => clearTimeout(timer.current), []);

  useEffect(() => {
    if (!openId) return undefined;
    const onDown = (e) => {
      const root = roots.current[openId];
      if (root && !root.contains(e.target)) setOpenId(null);
    };
    const onKey = (e) => {
      if (e.key !== 'Escape') return;
      const root = roots.current[openId];
      setOpenId(null);
      root?.querySelector('[data-menu-trigger]')?.focus();
    };
    document.addEventListener('pointerdown', onDown);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('pointerdown', onDown);
      document.removeEventListener('keydown', onKey);
    };
  }, [openId]);

  const close = useCallback(() => setOpenId(null), []);

  const bind = (id, { hover = false } = {}) => {
    const open = openId === id;
    const closeThis = () => setOpenId((cur) => (cur === id ? null : cur));
    return {
      open,
      close,
      rootProps: {
        ref: (el) => {
          if (el) roots.current[id] = el;
          else delete roots.current[id];
        },
        onPointerEnter: hover ? (e) => {
          if (e.pointerType !== 'mouse') return;
          clearTimeout(timer.current);
          setOpenId((cur) => {
            if (cur !== id) hoverOpenedAt.current = Date.now();
            return id;
          });
        } : undefined,
        onPointerLeave: hover ? (e) => {
          if (e.pointerType !== 'mouse') return;
          clearTimeout(timer.current);
          timer.current = setTimeout(closeThis, 180);
        } : undefined,
        // Keyboard focus moving out of the menu closes it (clicks are handled by pointerdown)
        onBlur: (e) => {
          if (e.relatedTarget && !e.currentTarget.contains(e.relatedTarget)) closeThis();
        },
      },
      triggerProps: {
        'data-menu-trigger': '',
        'aria-expanded': open,
        'aria-controls': `menu-${id}`,
        'aria-haspopup': 'true',
        onClick: () => {
          // A mouse click right after hover opened the menu must not close it again
          if (open && hover && Date.now() - hoverOpenedAt.current < 700) {
            hoverOpenedAt.current = 0;
            return;
          }
          setOpenId(open ? null : id);
        },
        onKeyDown: (e) => {
          if (e.key !== 'ArrowDown') return;
          e.preventDefault();
          setOpenId(id);
          // After React has rendered the panel (discrete events commit synchronously)
          setTimeout(() => roots.current[id]?.querySelector('[data-menu-item]')?.focus(), 0);
        },
      },
      panelProps: { id: `menu-${id}`, onKeyDown: arrowNav },
    };
  };

  return { openId, close, bind };
}
