# Fail on a translated string reaching a markup slot without esc().
#
# A hardcoded literal in an HTML attribute needs no escaping; a catalog lookup
# does, because a catalog is a file on disk. The i18n sweep turned the first
# into the second without upgrading the escaping, and the convention had no
# enforcement, so it decayed at a few dozen call sites.
#
#   python3 bin/i18n/check-escaping.py assets/js/app.js
#
# The rule: inside any ${ ... } that emits into markup, EVERY __()/_n() call
# must be an argument of esc(). Passing a translated string to a helper that
# escapes at its own boundary is fine and is detected as such.
import re, sys

src = open( sys.argv[1] if len( sys.argv ) > 1 else 'assets/js/app.js' ).read()
call = re.compile( r'\b(__|_n)\s*\(' )
bad, i = [], 0
while True:
    i = src.find( '${', i )
    if i < 0:
        break
    depth, j = 0, i + 1
    while j < len( src ):
        if src[ j ] == '{':
            depth += 1
        elif src[ j ] == '}':
            depth -= 1
            if depth == 0:
                break
        j += 1
    expr = re.sub( r'/\*.*?\*/', '', src[ i + 2 : j ], flags=re.S )
    # A nested template literal is a container; its own leaves are checked too.
    # An outer esc() spanning the whole expression escapes everything inside it.
    st = expr.strip()
    if st.startswith( 'esc(' ):
        d2, closed = 0, False
        for idx, c in enumerate( st ):
            if c == '(':
                d2 += 1
            elif c == ')':
                d2 -= 1
                if d2 == 0:
                    closed = ( idx == len( st ) - 1 )
                    break
        if closed:
            i = j + 1
            continue
    if '`' not in expr:
        for m in call.finditer( expr ):
            before = expr[ : m.start() ].rstrip()
            if before.endswith( 'esc(' ) or before.endswith( 'esc( ' ):
                continue
            # Argument to a helper that owns its escaping (not a bare emit).
            # Argument to a helper that escapes at its own boundary: the
            # nearest unclosed call before this point is not sprintf/__/_n.
            depth2, k, enclosing = 0, m.start() - 1, ''
            while k >= 0:
                c = expr[ k ]
                if c == ')':
                    depth2 += 1
                elif c == '(':
                    if depth2 == 0:
                        mm = re.search( r'([a-zA-Z_$][\w$.]*)\s*$', expr[ :k ] )
                        enclosing = mm.group( 1 ) if mm else ''
                        break
                    depth2 -= 1
                k -= 1
            if enclosing and enclosing not in ( 'sprintf', '__', '_n' ):
                continue
            ln = src.count( '\n', 0, i ) + 1
            bad.append( ( ln, expr.strip()[ :110 ].replace( '\n', ' ' ) ) )
            break
    i = j + 1

for ln, e in bad:
    print( "%5d  %s" % ( ln, e ) )
print( "unescaped translated interpolations:", len( bad ) )
sys.exit( 1 if bad else 0 )
