#!/usr/bin/env python3
"""Minn's core icon set: original 24px line icons in Minn's design language.

One icon for each name WordPress registers as core/<name>, drawn here from
scratch (never traced from WordPress's icon library, Dashicons or any other
set). Every icon sits on a 24 grid with a 1.8 currentColor stroke, round
caps and joins, about 3px of breathing room, and fill only where a pair
needs a solid partner (stars, symbols, dots). Minn Engine registers them
from the JSON under WordPress's names.

Run: python3 bin/icons/core-icons.py   (writes assets/icons/core-icons.json)
"""
import json, math, os, sys

HEAD = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
SOLID = 'fill="currentColor" stroke="none"'


def star_points(cx=12, cy=12.6, outer=8.6, inner=3.75):
    pts = []
    for i in range(10):
        r = outer if i % 2 == 0 else inner
        a = math.radians(-90 + i * 36)
        pts.append((round(cx + r * math.cos(a), 2), round(cy + r * math.sin(a), 2)))
    return pts


def star_path(pts):
    return 'M' + ' '.join(f'{x} {y}' for x, y in pts) + 'Z'


STAR = star_points()
# Left half: the top point, the left points, and the inner bottom point,
# closed along the vertical axis.
HALF = [STAR[0], STAR[9], STAR[8], STAR[7], STAR[6], STAR[5]]


def dot(cx, cy, r=1.35):
    return f'<circle cx="{cx}" cy="{cy}" r="{r}" {SOLID}/>'


I = {
    # Arrows: a shaft and an open head, eight directions.
    'arrow-up': '<path d="M12 19.5V5"/><path d="M6.5 10.5 12 5l5.5 5.5"/>',
    'arrow-down': '<path d="M12 4.5V19"/><path d="M6.5 13.5 12 19l5.5-5.5"/>',
    'arrow-left': '<path d="M19.5 12H5"/><path d="M10.5 6.5 5 12l5.5 5.5"/>',
    'arrow-right': '<path d="M4.5 12H19"/><path d="M13.5 6.5 19 12l-5.5 5.5"/>',
    'arrow-up-right': '<path d="M6.5 17.5 17 7"/><path d="M9 7h8v8"/>',
    'arrow-up-left': '<path d="M17.5 17.5 7 7"/><path d="M15 7H7v8"/>',
    'arrow-down-right': '<path d="M6.5 6.5 17 17"/><path d="M17 9v8H9"/>',
    'arrow-down-left': '<path d="M17.5 6.5 7 17"/><path d="M7 9v8h8"/>',
    # Chevrons, full size and small.
    'chevron-up': '<path d="M6 14.75 12 8.75l6 6"/>',
    'chevron-down': '<path d="M6 9.25 12 15.25l6-6"/>',
    'chevron-left': '<path d="M14.75 6 8.75 12l6 6"/>',
    'chevron-right': '<path d="M9.25 6 15.25 12l-6 6"/>',
    'chevron-up-small': '<path d="M8.75 13.6 12 10.4l3.25 3.2"/>',
    'chevron-down-small': '<path d="M8.75 10.4 12 13.6l3.25-3.2"/>',
    'chevron-left-small': '<path d="M13.6 8.75 10.4 12l3.2 3.25"/>',
    'chevron-right-small': '<path d="M10.4 8.75 13.6 12l-3.2 3.25"/>',
    'chevron-up-down': '<path d="M8 9.5 12 5.5l4 4"/><path d="M8 14.5l4 4 4-4"/>',
    # Symbols and status.
    'at-symbol': '<circle cx="12" cy="12" r="3.4"/><path d="M15.4 8.6v4.9a2.5 2.5 0 0 0 5 0V12a8.4 8.4 0 1 0-3.4 6.75"/>',
    'check': '<path d="M5 12.6l4.4 4.4L19 7.4"/>',
    'plus': '<path d="M12 5v14M5 12h14"/>',
    'plus-circle': '<circle cx="12" cy="12" r="8.5"/><path d="M12 8.4v7.2M8.4 12h7.2"/>',
    'create': '<rect x="4" y="4" width="16" height="16" rx="3.5"/><path d="M12 8.4v7.2M8.4 12h7.2"/>',
    'info': '<circle cx="12" cy="12" r="8.5"/><path d="M12 11.2v5"/><path d="M12 7.9v.1"/>',
    'help': '<circle cx="12" cy="12" r="8.5"/><path d="M9.7 9.8a2.4 2.4 0 0 1 4.65.8c0 1.65-2.35 2.05-2.35 3.6"/><path d="M12 16.9v.1"/>',
    'error': '<circle cx="12" cy="12" r="8.5"/><path d="M9.2 9.2l5.6 5.6M14.8 9.2l-5.6 5.6"/>',
    'caution': '<path d="M10.6 4.8a1.6 1.6 0 0 1 2.8 0l7.25 12.75a1.6 1.6 0 0 1-1.4 2.4H4.75a1.6 1.6 0 0 1-1.4-2.4Z"/><path d="M12 9.6v4.2"/><path d="M12 16.6v.1"/>',
    'published': '<circle cx="12" cy="12" r="8.5"/><path d="M8.4 12.3l2.5 2.5 4.8-5"/>',
    'scheduled': '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.4V12l3.2 2.1"/>',
    'tip': '<path d="M9.3 17.1h5.4"/><path d="M10.3 20h3.4"/><path d="M9.3 17.1v-.6c0-.95-.45-1.75-1.15-2.4a5.5 5.5 0 1 1 7.7 0c-.7.65-1.15 1.45-1.15 2.4v.6"/>',
    'shield': '<path d="M12 3.5l7 2.9v5.4c0 4.3-2.9 7.75-7 8.7-4.1-.95-7-4.4-7-8.7V6.4Z"/>',
    'key': '<circle cx="8.2" cy="15.8" r="4.1"/><path d="M11.1 12.9 19.5 4.5"/><path d="M16.3 7.7l2.5 2.5"/><path d="M14.2 9.8l1.9 1.9"/>',
    'bell': '<path d="M6.5 16.2V11a5.5 5.5 0 0 1 11 0v5.2l1.6 2.3H4.9Z"/><path d="M10.1 20.9a2 2 0 0 0 3.8 0"/>',
    'search': '<circle cx="10.6" cy="10.6" r="6.1"/><path d="M15.1 15.1l4.6 4.6"/>',
    # Text and layout blocks.
    'paragraph': '<path d="M13.2 4.5v15M17 4.5v15"/><path d="M18.2 4.5H10a4 4 0 0 0 0 8h3.2"/>',
    'heading': '<path d="M6.5 5v14M17.5 5v14M6.5 12h11"/>',
    'verse': '<path d="M19.6 4.4c-6.7.2-11.4 4.6-12.4 11.2L4.8 19.4l3.6-2.4c6.6-1 11-5.7 11.2-12.6Z"/><path d="M8 16.2l7-7"/>',
    'quote': '<rect x="4.6" y="11.4" width="4.6" height="4.6" rx="1.4"/><path d="M4.6 13.4v-1.6c0-2.6 1.5-4.5 4-5.6"/><rect x="13.6" y="11.4" width="4.6" height="4.6" rx="1.4"/><path d="M13.6 13.4v-1.6c0-2.6 1.5-4.5 4-5.6"/>',
    'menu': '<path d="M4.5 7h15M4.5 12h15M4.5 17h15"/>',
    'more-horizontal': dot(6, 12) + dot(12, 12) + dot(18, 12),
    'more-vertical': dot(12, 6) + dot(12, 12) + dot(12, 18),
    'table': '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M9 10v9M15 10v9"/>',
    'block-table': '<rect x="4" y="4" width="16" height="16" rx="3"/><path d="M4 9.4h16M4 14.7h16M12 9.4V20"/>',
    'block-default': '<path d="M12 3.6l7.4 4.2v8.4L12 20.4l-7.4-4.2V7.8Z"/><path d="M4.6 7.8 12 12l7.4-4.2M12 12v8.4"/>',
    'block-meta': '<rect x="4" y="4" width="16" height="16" rx="3"/><path d="M7.6 9.4h2.8M13.6 9.4h2.8M7.6 14.6h2.8M13.6 14.6h2.8"/>',
    'group': '<rect x="3.5" y="3.5" width="17" height="17" rx="3" stroke-dasharray="2.6 2.6"/><rect x="7.4" y="7.4" width="9.2" height="3.8" rx="1.1"/><rect x="7.4" y="12.8" width="9.2" height="3.8" rx="1.1"/>',
    'cover': '<rect x="3" y="4.5" width="18" height="15" rx="2.5"/><path d="M8 11h8M9.6 14.4h4.8"/>',
    'shadow': '<rect x="4" y="4" width="12" height="12" rx="2.5"/><path d="M19.5 8.5v9a2 2 0 0 1-2 2h-9"/>',
    'styles': '<circle cx="9.2" cy="9.6" r="5"/><circle cx="14.8" cy="9.6" r="5"/><circle cx="12" cy="14.4" r="5"/>',
    'symbol': '<path d="M12 3.4 20.6 12 12 20.6 3.4 12Z"/><path d="M12 8.6 15.4 12 12 15.4 8.6 12Z"/>',
    'symbol-filled': '<path d="M12 3.4 20.6 12 12 20.6 3.4 12Z" fill="currentColor"/>',
    'drawer-left': '<rect x="3.5" y="4.5" width="17" height="15" rx="2.5"/><path d="M9.5 4.5v15"/>',
    'drawer-right': '<rect x="3.5" y="4.5" width="17" height="15" rx="2.5"/><path d="M14.5 4.5v15"/>',
    # Media.
    'image': '<rect x="3.5" y="4.5" width="17" height="15" rx="2.5"/><circle cx="8.9" cy="9.8" r="1.7"/><path d="M3.9 17.4 8.6 12.7l4 4 2.5-2.5 5 5"/>',
    'gallery': '<rect x="7.5" y="3.5" width="13" height="13" rx="2"/><path d="M4.5 7.5v11a2 2 0 0 0 2 2h11"/><path d="M7.9 13.1l3.1-3.1 4 4 1.5-1.5 3.6 3.6"/>',
    'audio': '<path d="M9 17.2V6.6l10-2.1v10.7"/><circle cx="6.6" cy="17.2" r="2.4"/><circle cx="16.6" cy="15.2" r="2.4"/>',
    'capture-photo': '<path d="M4 8.6A1.6 1.6 0 0 1 5.6 7h2.2l1.5-2.1h5.4L16.2 7h2.2A1.6 1.6 0 0 1 20 8.6v8.8a1.6 1.6 0 0 1-1.6 1.6H5.6A1.6 1.6 0 0 1 4 17.4Z"/><circle cx="12" cy="12.8" r="3.3"/>',
    'capture-video': '<rect x="3" y="7" width="12" height="10" rx="2"/><path d="M15 10.4l5.5-3.1v9.4L15 13.6Z"/>',
    'file': '<path d="M7 3.5h6.6l4.9 4.9V19a1.5 1.5 0 0 1-1.5 1.5H7A1.5 1.5 0 0 1 5.5 19V5A1.5 1.5 0 0 1 7 3.5Z"/><path d="M13.6 3.5v4.9h4.9"/>',
    'download': '<path d="M12 4v11"/><path d="M7.5 10.5 12 15l4.5-4.5"/><path d="M5 19.5h14"/>',
    'upload': '<path d="M12 15V4"/><path d="M7.5 8.5 12 4l4.5 4.5"/><path d="M5 19.5h14"/>',
    'external': '<path d="M18 13.4V18a1.5 1.5 0 0 1-1.5 1.5h-10A1.5 1.5 0 0 1 5 18V8a1.5 1.5 0 0 1 1.5-1.5H11"/><path d="M14 4.5h5.5V10"/><path d="M19.5 4.5l-8 8"/>',
    'next': '<path d="M6 6.5 14 12l-8 5.5Z"/><path d="M17.8 6.5v11"/>',
    'previous': '<path d="M18 6.5 10 12l8 5.5Z"/><path d="M6.2 6.5v11"/>',
    'shuffle': '<path d="M4 7.5h3.3c4.6 0 4.6 9 9.2 9H20"/><path d="M4 16.5h3.3c1.4 0 2.35-.8 3.05-2"/><path d="M20 7.5h-3.5c-1.4 0-2.35.8-3.05 2"/><path d="M17.5 5 20 7.5 17.5 10"/><path d="M17.5 14 20 16.5 17.5 19"/>',
    # Devices.
    'desktop': '<rect x="3" y="4.5" width="18" height="12" rx="2"/><path d="M9 20h6M12 16.5V20"/>',
    'tablet': '<rect x="5" y="3" width="14" height="18" rx="2.5"/><path d="M10.6 17.9h2.8"/>',
    'mobile': '<rect x="7" y="3" width="10" height="18" rx="2.5"/><path d="M11 17.9h2"/>',
    # Places, people and things.
    'home': '<path d="M4 10.6 12 4l8 6.6V19a1.5 1.5 0 0 1-1.5 1.5H15V15H9v5.5H5.5A1.5 1.5 0 0 1 4 19Z"/>',
    'map-marker': '<path d="M12 21s-6.5-5.6-6.5-11a6.5 6.5 0 0 1 13 0c0 5.4-6.5 11-6.5 11Z"/><circle cx="12" cy="10" r="2.4"/>',
    'language': '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17"/><path d="M12 3.5c-2.5 2.4-3.7 5.3-3.7 8.5s1.2 6.1 3.7 8.5c2.5-2.4 3.7-5.3 3.7-8.5S14.5 5.9 12 3.5Z"/>',
    'people': '<circle cx="9" cy="8.6" r="3.1"/><path d="M3.5 19.2a5.5 5.5 0 0 1 11 0"/><circle cx="16.6" cy="9.4" r="2.5"/><path d="M15.9 14.2a4.4 4.4 0 0 1 4.6 4.4"/>',
    'calendar': '<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/><path d="M7.9 14.2h.1M12 14.2h.1M16.1 14.2h.1M7.9 17.4h.1M12 17.4h.1"/>',
    'envelope': '<rect x="3" y="5.5" width="18" height="13" rx="2"/><path d="M3.6 7.2 12 13l8.4-5.8"/>',
    'comment': '<path d="M5 5.5h14A1.5 1.5 0 0 1 20.5 7v8.4A1.5 1.5 0 0 1 19 16.9h-8.2l-4.3 3.4v-3.4H5a1.5 1.5 0 0 1-1.5-1.5V7A1.5 1.5 0 0 1 5 5.5Z"/>',
    'rss': dot(6, 18, 1.5) + '<path d="M5 11a8 8 0 0 1 8 8"/><path d="M5 5a14 14 0 0 1 14 14"/>',
    'share': '<circle cx="17.4" cy="6" r="2.3"/><circle cx="6.6" cy="12" r="2.3"/><circle cx="17.4" cy="18" r="2.3"/><path d="M8.6 10.9l6.8-3.8M8.6 13.1l6.8 3.8"/>',
    'pencil': '<path d="M15.5 4.6a2.1 2.1 0 0 1 3 3L8 18.1l-4 .9.9-4Z"/><path d="M13.5 6.6l3 3"/>',
    'tag': '<path d="M3.5 11.9V5a1.5 1.5 0 0 1 1.5-1.5h6.9a1.5 1.5 0 0 1 1.06.44l7.6 7.6a1.5 1.5 0 0 1 0 2.12l-6.9 6.9a1.5 1.5 0 0 1-2.12 0l-7.6-7.6A1.5 1.5 0 0 1 3.5 11.9Z"/><circle cx="8" cy="8" r="1.5"/>',
    'category': '<path d="M3.5 7.5A1.5 1.5 0 0 1 5 6h4.1l1.9 2.1H19a1.5 1.5 0 0 1 1.5 1.5v8A1.5 1.5 0 0 1 19 19H5a1.5 1.5 0 0 1-1.5-1.5Z"/>',
    'settings': '<path d="M4 7h9.4M18.4 7H20M4 17h1.6M10.6 17H20"/><circle cx="15.9" cy="7" r="2.5"/><circle cx="8.1" cy="17" r="2.5"/>',
    'chart-bar': '<rect x="4.4" y="12" width="3.6" height="8" rx="1"/><rect x="10.2" y="4.5" width="3.6" height="15.5" rx="1"/><rect x="16" y="8.6" width="3.6" height="11.4" rx="1"/>',
    # Commerce.
    'cart': '<path d="M3 4.5h2.3l2.1 10.1a1.5 1.5 0 0 0 1.47 1.2h8.4a1.5 1.5 0 0 0 1.45-1.12L20.5 8H6.1"/><circle cx="9.6" cy="19.6" r="1.3"/><circle cx="17" cy="19.6" r="1.3"/>',
    'payment': '<rect x="3" y="5.5" width="18" height="13" rx="2"/><path d="M3 10h18M7 14.8h3.4"/>',
    'receipt': '<path d="M6 3.5h12v17l-2-1.5-2 1.5-2-1.5-2 1.5-2-1.5-2 1.5Z"/><path d="M9 8h6M9 11.5h6M9 15h3.5"/>',
    'store': '<path d="M2.6 9 4.1 4.5h15.8L21.4 9a2.35 2.35 0 0 1-4.7 0 2.35 2.35 0 0 1-4.7 0 2.35 2.35 0 0 1-4.7 0 2.35 2.35 0 0 1-4.7 0Z"/><path d="M4.6 11.6V20h14.8v-8.4"/><path d="M10 20v-4.9h4V20"/>',
    # Ratings.
    'star-empty': f'<path d="{star_path(STAR)}"/>',
    'star-filled': f'<path d="{star_path(STAR)}" fill="currentColor"/>',
    'star-half': f'<path d="{star_path(STAR)}"/><path d="{star_path(HALF)}" fill="currentColor"/>',
}

if __name__ == '__main__':
    out = {name: HEAD + body + '</svg>' for name, body in sorted(I.items())}
    dest = sys.argv[1] if len(sys.argv) > 1 else os.path.join(os.path.dirname(__file__), '..', '..', 'assets', 'icons', 'core-icons.json')
    with open(dest, 'w') as fh:
        json.dump(out, fh, indent=1, ensure_ascii=False)
        fh.write('\n')
    print(len(out), 'icons')
