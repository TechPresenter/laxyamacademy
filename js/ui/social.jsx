// Brand-coloured social links (admin → Settings → Social media). Only networks with a URL render.
import { Icon } from '../components.jsx';
import { SOCIAL } from './shell-data.js';

const SIZES = {
  xs: ['size-7', 'size-3.5'],
  sm: ['size-8', 'size-4'],
  md: ['size-10', 'size-[18px]'],
};

/**
 * variant 'brand' (default): white glyph on the network's brand colour.
 * variant 'ghost': white glyph on a translucent circle, brand colour on hover (dark bars).
 */
export function SocialLinks({ social, size = 'md', variant = 'brand', className = '' }) {
  const items = (social || []).filter((s) => s?.url);
  if (!items.length) return null;
  const [box, icon] = SIZES[size] || SIZES.md;
  return (
    <ul className={'flex flex-wrap items-center gap-2 ' + className}>
      {items.map((s) => {
        const [label, iconName, brand] = SOCIAL[s.network] || [s.network, 'link', '#0B63F6'];
        const ghost = variant === 'ghost';
        return (
          <li key={s.network}>
            <a href={s.url} target="_blank" rel="noopener noreferrer" aria-label={label} title={label}
              className={`group relative grid ${box} place-items-center overflow-hidden rounded-full text-white shadow-sm ring-1 ring-white/10 transition-all duration-300 hover:-translate-y-0.5 hover:shadow-lg ${ghost ? 'bg-white/10' : ''}`}
              style={ghost ? undefined : { background: brand }}>
              {ghost && <span aria-hidden="true" className="absolute inset-0 opacity-0 transition-opacity duration-300 group-hover:opacity-100" style={{ background: brand }} />}
              <Icon name={iconName} className={'relative ' + icon} />
            </a>
          </li>
        );
      })}
    </ul>
  );
}
