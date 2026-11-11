"""
Prefer IPv4 for outgoing connections.

On networks where IPv6 is advertised but not routed, Python's socket.create_connection tries the IPv6 address first and
waits out the whole timeout (up to 45 s for Gemini, 60 s for OpenStreetMap) before falling back to IPv4. Dropping the
IPv6 addresses from name resolution makes every outbound call (Gemini, Overpass, Nominatim, Wikipedia) connect at once.
Import this module before anything that opens a connection.
"""
import socket

_orig_getaddrinfo = socket.getaddrinfo


def _prefer_ipv4(host, port, family=0, type=0, proto=0, flags=0):
    res = _orig_getaddrinfo(host, port, family, type, proto, flags)
    v4 = [r for r in res if r[0] == socket.AF_INET]
    return v4 or res


socket.getaddrinfo = _prefer_ipv4
