#!/usr/bin/env python3
"""Outline Minn's stroke-drawn core icons into filled paths.

WordPress's icon registry (and Minn Engine's) keeps only <svg> with xmlns and
viewBox, <path> with d / fill / fill-rule / transform and <polygon>: strokes,
circles, rects and lines are stripped. So every stroke is outlined at its own
width with round caps and joins (dashes kept), every shape becomes a path,
and each icon is unioned into one simple path that reads the same under
evenodd and nonzero.

Skia's stroker approximates offset curves to about a quarter unit, which is
coarse on a 24 grid, so the geometry is outlined at 20x and scaled back.

Needs picosvg and skia-pathops (both Apache-2.0):
  python3 -m venv /tmp/minn-icons && /tmp/minn-icons/bin/pip install picosvg skia-pathops
Run:  /tmp/minn-icons/bin/python bin/icons/outline.py   (or pass <stroke.json> <filled.json>)
"""
import json, os, re, sys
import xml.etree.ElementTree as ET

import pathops
from picosvg.svg_pathops import skia_path, svg_commands
from picosvg.svg_transform import Affine2D
from picosvg.svg_types import SVGCircle, SVGPath, SVGRect

SCALE = 20
HEAD = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
CAP = {'round': pathops.LineCap.ROUND_CAP, 'butt': pathops.LineCap.BUTT_CAP, 'square': pathops.LineCap.SQUARE_CAP}
JOIN = {'round': pathops.LineJoin.ROUND_JOIN, 'miter': pathops.LineJoin.MITER_JOIN, 'bevel': pathops.LineJoin.BEVEL_JOIN}


def shape_path(el):
    tag = el.tag.split('}')[-1]
    a = el.attrib
    num = lambda k, d=0.0: float(a.get(k, d))
    if tag == 'path':
        return SVGPath(d=a['d'])
    if tag == 'circle':
        return SVGCircle(cx=num('cx'), cy=num('cy'), r=num('r')).as_path()
    if tag == 'rect':
        return SVGRect(x=num('x'), y=num('y'), width=num('width'), height=num('height'), rx=num('rx'), ry=num('rx')).as_path()
    raise ValueError('unexpected element ' + tag)


def compact(d):
    """Shortest plain spelling of a path: no commas, no space beside a command
    letter or before a minus, no leading or trailing zeros."""
    def num(m):
        v = m.group(0)
        if '.' in v:
            v = v.rstrip('0').rstrip('.')
        if v in ('-0', ''):
            v = '0'
        if v.startswith('0.'):
            v = v[1:]
        elif v.startswith('-0.'):
            v = '-' + v[2:]
        return v
    d = re.sub(r'-?\d+(?:\.\d+)?', num, d)
    d = d.replace(',', ' ')
    d = re.sub(r'\s*([MLQCZmlqcz])\s*', r'\1', d)
    d = re.sub(r' (?=-)', '', d)
    return d.strip()


def outline(svg_text):
    root = ET.fromstring(svg_text)
    base = dict(root.attrib)
    up = Affine2D.identity().scale(SCALE)
    pieces = []
    for el in root:
        attrs = {**base, **el.attrib}
        cmds = shape_path(el).absolute(inplace=False).apply_transform(up).as_cmd_seq()
        if attrs.get('fill', 'black') not in ('none', ''):
            pieces.append(skia_path(cmds, 'nonzero'))
        if attrs.get('stroke', 'none') != 'none':
            sk = skia_path(cmds, 'nonzero')
            dash = attrs.get('stroke-dasharray')
            sk.stroke(
                float(attrs.get('stroke-width', 1)) * SCALE,
                CAP[attrs.get('stroke-linecap', 'butt')],
                JOIN[attrs.get('stroke-linejoin', 'miter')],
                4,
                dash_array=[float(v) * SCALE for v in re.split(r'[\s,]+', dash.strip())] if dash else None,
            )
            sk.convertConicsToQuads(0.04)
            pieces.append(sk)
    builder = pathops.OpBuilder(fix_winding=True)
    for sk in pieces:
        builder.add(sk, pathops.PathOp.UNION)
    union = builder.resolve()
    down = Affine2D.identity().scale(1 / SCALE)
    path = SVGPath.from_commands(svg_commands(union)).apply_transform(down).round_floats(2)
    d = compact(path.d)
    return HEAD + f'<path d="{d}" fill="currentColor" fill-rule="evenodd"/></svg>'


if __name__ == '__main__':
    here = os.path.dirname(os.path.abspath(__file__))
    src_file = sys.argv[1] if len(sys.argv) > 1 else os.path.join(here, 'core-icons-stroke.json')
    dest = sys.argv[2] if len(sys.argv) > 2 else os.path.join(here, '..', '..', 'assets', 'icons', 'core-icons.json')
    src = json.load(open(src_file))
    out = {name: outline(svg) for name, svg in sorted(src.items())}
    with open(dest, 'w') as fh:
        json.dump(out, fh, indent=1, ensure_ascii=False)
        fh.write('\n')
    print(len(out), 'icons outlined')
