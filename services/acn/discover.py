#!/usr/bin/env python3
"""
Entertainment network device discovery for Lightbox Server.

Discovers devices via two protocols and registers them in DNS:

  1. ACN / SLP (ANSI E1.17, RFC 2608)  — multicast 239.255.255.253 port 427
     Targets ACN-compliant components that advertise service:acn.esta.

  2. Art-Net ArtPoll (Art-Net 4)  — broadcast port 6454
     ETC Eos, Ion, Element and most other lighting consoles/gateways respond
     to ArtPoll and also broadcast ArtPollReply every few seconds unprompted.

One SLP socket and one Art-Net TX socket are opened per non-loopback IPv4
interface so that multicast and broadcast reach all attached networks.
"""

import fcntl, os, re, select, socket, struct, time, logging
import urllib.request, urllib.parse, urllib.error

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

SLP_MCAST     = '239.255.255.253'
SLP_PORT      = 427
ACN_SVC_TYPE  = 'service:acn.esta'
# Try multiple scopes per poll — ETC Eos uses ETCRD, others may use DEFAULT or ACN.
# Empty string means "any scope" per RFC 2608 s.8.1 but some devices ignore it.
SLP_SCOPES    = ['DEFAULT', 'ETCRD', 'ACN', '']

ARTNET_PORT   = 6454
ARTNET_ID     = b'Art-Net\x00'
OP_POLL       = 0x2000
OP_POLL_REPLY = 0x2100

API_URL       = os.getenv('API_URL', 'http://localhost:8080/api.php')
POLL_INTERVAL = int(os.getenv('POLL_INTERVAL', '30'))
STARTUP_DELAY = int(os.getenv('STARTUP_DELAY', '15'))

log = logging.getLogger('discover')

# ---------------------------------------------------------------------------
# Interface enumeration
# ---------------------------------------------------------------------------

def _iface_to_ip(name: str) -> str:
    """Return the IPv4 address of a named interface via SIOCGIFADDR ioctl."""
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as s:
        return socket.inet_ntoa(fcntl.ioctl(
            s.fileno(),
            0x8915,  # SIOCGIFADDR
            struct.pack('256s', name[:15].encode())
        )[20:24])


def list_ipv4_interfaces() -> list[tuple[str, str]]:
    """Return [(name, ipv4_addr)] for all UP non-loopback IPv4 interfaces."""
    results = []
    try:
        with open('/proc/net/dev') as f:
            names = [line.split(':')[0].strip()
                     for line in f.readlines()[2:] if ':' in line]
    except OSError:
        return results
    for name in names:
        if name == 'lo':
            continue
        try:
            ip = _iface_to_ip(name)
            if ip and ip != '0.0.0.0':
                results.append((name, ip))
        except OSError:
            pass
    return results

# ---------------------------------------------------------------------------
# SLP packet building (RFC 2608)
# ---------------------------------------------------------------------------

def _slp_header(func_id: int, body: bytes, xid: int = 1, flags: int = 0) -> bytes:
    lang  = b'en'
    total = 12 + 2 + len(lang) + len(body)
    return (
        bytes([2, func_id]) +
        struct.pack('>I', total)[1:] +   # 3-byte big-endian length
        struct.pack('>H', flags) +
        b'\x00\x00\x00' +               # extension offset = 0
        struct.pack('>H', xid) +
        struct.pack('>H', len(lang)) +
        lang +
        body
    )


def build_srvreqst(xid: int, scope: str = 'DEFAULT') -> bytes:
    svc     = ACN_SVC_TYPE.encode()
    scope_b = scope.encode()
    return _slp_header(1, (
        b'\x00\x00' +
        struct.pack('>H', len(svc))     + svc     +
        struct.pack('>H', len(scope_b)) + scope_b +
        b'\x00\x00' +
        b'\x00\x00'
    ), xid)


def build_attrrqst(url: str, xid: int) -> bytes:
    url_b = url.encode()
    scope = b''   # empty = any scope, consistent with build_srvreqst
    tags  = b'device-description'
    return _slp_header(6, (
        b'\x00\x00' +
        struct.pack('>H', len(url_b)) + url_b  +
        struct.pack('>H', len(scope)) + scope  +
        struct.pack('>H', len(tags))  + tags   +
        b'\x00\x00'
    ), xid)

# ---------------------------------------------------------------------------
# SLP packet parsing
# ---------------------------------------------------------------------------

def _slp_body_offset(data: bytes) -> int | None:
    if len(data) < 14:
        return None
    return 14 + struct.unpack('>H', data[12:14])[0]


def parse_srvreply(data: bytes) -> list[str]:
    if len(data) < 2 or data[1] != 2:
        return []
    off = _slp_body_offset(data)
    if off is None or len(data) < off + 4:
        return []
    if struct.unpack('>H', data[off:off+2])[0] != 0:
        return []
    count = struct.unpack('>H', data[off+2:off+4])[0]
    off  += 4
    urls  = []
    for _ in range(count):
        if len(data) < off + 5:
            break
        url_len = struct.unpack('>H', data[off+3:off+5])[0]
        off    += 5
        urls.append(data[off:off+url_len].decode('utf-8', errors='ignore'))
        off    += url_len
        off    += 1
    return urls


def parse_attrrply(data: bytes) -> str:
    if len(data) < 2 or data[1] != 7:
        return ''
    off = _slp_body_offset(data)
    if off is None or len(data) < off + 4:
        return ''
    if struct.unpack('>H', data[off:off+2])[0] != 0:
        return ''
    attr_len = struct.unpack('>H', data[off+2:off+4])[0]
    off     += 4
    return data[off:off+attr_len].decode('utf-8', errors='ignore')


def parse_srvreg(data: bytes) -> tuple[str, str] | None:
    if len(data) < 2 or data[1] != 3:
        return None
    off = _slp_body_offset(data)
    if off is None or len(data) < off + 5:
        return None
    url_len = struct.unpack('>H', data[off+3:off+5])[0]
    off    += 5
    url     = data[off:off+url_len].decode('utf-8', errors='ignore')
    off    += url_len + 1   # skip num_auth
    for _ in range(2):      # skip service-type and scope-list
        if len(data) < off + 2:
            return url, ''
        off += 2 + struct.unpack('>H', data[off:off+2])[0]
    if len(data) < off + 2:
        return url, ''
    attr_len = struct.unpack('>H', data[off:off+2])[0]
    off     += 2
    return url, (data[off:off+attr_len].decode('utf-8', errors='ignore') if attr_len else '')

# ---------------------------------------------------------------------------
# Art-Net packets (Art-Net 4 spec)
# ---------------------------------------------------------------------------

def build_artpoll() -> bytes:
    return (
        ARTNET_ID                  +
        struct.pack('<H', OP_POLL) +   # opcode is little-endian in Art-Net
        struct.pack('>H', 14)      +   # protocol version
        b'\x02'                    +   # TalkToMe: unicast replies
        b'\x00'                        # priority
    )


def parse_artpoll_reply(data: bytes) -> dict | None:
    """Parse ArtPollReply and return a dict with ip, mac, short_name, long_name."""
    if len(data) < 239 or data[:8] != ARTNET_ID:
        return None
    opcode = struct.unpack('<H', data[8:10])[0]
    if opcode != OP_POLL_REPLY:
        return None
    ip         = '.'.join(str(b) for b in data[10:14])
    mac        = ':'.join(f'{b:02x}' for b in data[201:207])
    short_name = data[26:44].rstrip(b'\x00').decode('utf-8', errors='ignore').strip()
    long_name  = data[44:108].rstrip(b'\x00').decode('utf-8', errors='ignore').strip()
    return {'ip': ip, 'mac': mac, 'short_name': short_name, 'long_name': long_name}

# ---------------------------------------------------------------------------
# Shared helpers
# ---------------------------------------------------------------------------

def parse_acn_url(url: str) -> tuple[str | None, str | None]:
    m = re.match(r'service:acn\.esta//([^/]+)/(.+)', url, re.I)
    if m:
        return m.group(1).strip('{}').lower(), m.group(2).strip()
    m = re.match(r'service:acn\.esta/(.+)', url, re.I)
    if m:
        return None, m.group(1).strip()
    return None, None


def extract_attr(attrs: str, *names: str) -> str:
    for name in names:
        m = re.search(r'\(' + re.escape(name) + r'=([^)]+)\)', attrs, re.I)
        if m:
            return m.group(1).strip()
    return ''


def to_hostname(description: str, cid: str | None) -> str:
    name = (description or '').lower()
    name = re.sub(r'[^a-z0-9]+', '-', name).strip('-')
    if name:
        return name[:63]
    suffix = ((cid or 'device').replace('-', ''))[-8:]
    return f'acn-{suffix}'


def register_device(cid: str | None, ip: str, description: str):
    hostname = to_hostname(description, cid)
    body = urllib.parse.urlencode({
        'cid':         cid or ip,
        'hostname':    hostname,
        'ip_address':  ip,
        'description': description,
    }).encode()
    try:
        req = urllib.request.Request(f'{API_URL}?action=acn_sync', data=body, method='POST')
        with urllib.request.urlopen(req, timeout=5):
            log.info('Registered  %-20s → %s', hostname, ip)
    except urllib.error.URLError as exc:
        log.warning('API error for %s: %s', ip, exc)

# ---------------------------------------------------------------------------
# Discovery engine
# ---------------------------------------------------------------------------

class Discoverer:
    def __init__(self):
        self._xid             = 1
        self._pending: dict[int, tuple] = {}   # xid → (cid, ip, url)
        self._scope_fallbacks: set[str] = set() # IPs registered via scope-error fallback this poll

    def _next_xid(self) -> int:
        x = self._xid
        self._xid = (self._xid % 0xFFFF) + 1
        return x

    # --- socket helpers ---

    @staticmethod
    def _slp_sock(local_ip: str) -> socket.socket:
        """SLP send+receive socket bound to port 427, pinned to local_ip.

        Sending from port 427 ensures SrvRply/AttrRply come back to the
        same socket we're select()-ing on.  IP_MULTICAST_IF pins which NIC
        the multicast leaves on so replies arrive on the correct interface.
        """
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        try:
            s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEPORT, 1)
        except AttributeError:
            pass
        s.setsockopt(socket.IPPROTO_IP, socket.IP_MULTICAST_TTL, 32)
        s.setsockopt(socket.IPPROTO_IP, socket.IP_MULTICAST_IF,
                     socket.inet_aton(local_ip))
        s.bind(('', SLP_PORT))
        mreq = struct.pack('4s4s', socket.inet_aton(SLP_MCAST),
                           socket.inet_aton(local_ip))
        s.setsockopt(socket.IPPROTO_IP, socket.IP_ADD_MEMBERSHIP, mreq)
        return s

    @staticmethod
    def _artnet_rx() -> socket.socket:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        try:
            s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEPORT, 1)
        except AttributeError:
            pass
        s.setsockopt(socket.SOL_SOCKET, socket.SO_BROADCAST, 1)
        s.bind(('', ARTNET_PORT))
        return s

    @staticmethod
    def _artnet_tx(local_ip: str) -> socket.socket:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.setsockopt(socket.SOL_SOCKET, socket.SO_BROADCAST, 1)
        s.bind((local_ip, 0))
        return s

    # --- packet handlers ---

    def _handle_slp(self, data: bytes, src_ip: str, slp: socket.socket):
        func = data[1] if len(data) >= 2 else 0

        if func == 2:   # SrvRply
            # Log any error code so scope mismatches are visible in docker logs
            off = _slp_body_offset(data)
            if off and len(data) >= off + 2:
                err = struct.unpack('>H', data[off:off+2])[0]
                if err:
                    _SLP_ERRORS = {4: 'SCOPE_NOT_SUPPORTED', 6: 'AUTHENTICATION_FAILED'}
                    log.info('SLP error %d (%s) from %s',
                             err, _SLP_ERRORS.get(err, 'unknown'), src_ip)
                    if err == 4 and src_ip not in self._scope_fallbacks:
                        # Device IS an ACN SA (it replied to service:acn.esta) but
                        # uses an unknown scope — register with a synthetic CID so
                        # it at least appears in DNS. Track it so we only register
                        # once per poll cycle rather than once per scope attempt.
                        self._scope_fallbacks.add(src_ip)
                        cid = 'acn-' + src_ip.replace('.', '')
                        register_device(cid, src_ip, '')
            for url in parse_srvreply(data):
                if ACN_SVC_TYPE.lower() not in url.lower():
                    continue
                cid, ip = parse_acn_url(url)
                if not ip:
                    continue
                xid = self._next_xid()
                self._pending[xid] = (cid, ip, url)
                try:
                    slp.sendto(build_attrrqst(url, xid), (ip, SLP_PORT))
                except OSError:
                    self._pending.pop(xid, None)
                    register_device(cid, ip, '')

        elif func == 3:  # SrvReg (passive announcement)
            result = parse_srvreg(data)
            if not result:
                return
            url, attrs = result
            if ACN_SVC_TYPE.lower() not in url.lower():
                return
            cid, ip = parse_acn_url(url)
            if not ip:
                ip = src_ip
            desc = extract_attr(attrs, 'device-description', 'description', 'name')
            register_device(cid, ip, desc)

        elif func == 7:  # AttrRply
            xid = next((k for k, (_, ip, _) in self._pending.items()
                        if ip == src_ip), None)
            if xid is not None:
                cid, ip, _ = self._pending.pop(xid)
                desc = extract_attr(parse_attrrply(data),
                                    'device-description', 'description', 'name')
                register_device(cid, ip, desc)

    @staticmethod
    def _handle_artnet(data: bytes):
        reply = parse_artpoll_reply(data)
        if not reply:
            return
        ip   = reply['ip']
        desc = reply['short_name'] or reply['long_name']
        cid  = 'artnet-' + reply['mac'].replace(':', '')
        register_device(cid, ip, desc)

    # --- main loop ---

    def run(self):
        ifaces = list_ipv4_interfaces()
        if not ifaces:
            log.warning('No non-loopback IPv4 interfaces found — discovery may not work')
            ifaces = [('(fallback)', '0.0.0.0')]

        log.info('Discovering on %d interface(s): %s',
                 len(ifaces),
                 ', '.join(f'{n} ({ip})' for n, ip in ifaces))

        slp_socks  = []
        artnet_txs = []
        for name, ip in ifaces:
            try:
                slp_socks.append(self._slp_sock(ip))
                artnet_txs.append(self._artnet_tx(ip))
                log.info('  SLP + Art-Net TX on %s (%s)', name, ip)
            except OSError as exc:
                log.warning('  Skipping %s (%s): %s', name, ip, exc)

        artnet_rx = self._artnet_rx()

        log.info('SLP  multicast %s port %d', SLP_MCAST, SLP_PORT)
        log.info('Art-Net listener: port %d', ARTNET_PORT)
        log.info('Polling every %ds', POLL_INTERVAL)

        last_poll = -POLL_INTERVAL   # poll immediately on first tick
        while True:
            now = time.monotonic()
            if now - last_poll >= POLL_INTERVAL:
                self._scope_fallbacks.clear()
                for scope in SLP_SCOPES:
                    for s in slp_socks:
                        xid = self._next_xid()
                        try:
                            s.sendto(build_srvreqst(xid, scope), (SLP_MCAST, SLP_PORT))
                            log.debug('SLP SrvRqst scope=%r xid=%d', scope, xid)
                        except OSError as exc:
                            log.warning('SLP send failed: %s', exc)
                for tx in artnet_txs:
                    try:
                        tx.sendto(build_artpoll(), ('255.255.255.255', ARTNET_PORT))
                    except OSError as exc:
                        log.warning('Art-Net poll failed: %s', exc)
                last_poll = now

            readable, _, _ = select.select(slp_socks + [artnet_rx], [], [], 1.0)
            for sock in readable:
                try:
                    data, (src_ip, _) = sock.recvfrom(65536)
                except OSError as exc:
                    log.warning('recv: %s', exc)
                    continue
                if sock in slp_socks:
                    self._handle_slp(data, src_ip, sock)
                else:
                    self._handle_artnet(data)


if __name__ == '__main__':
    logging.basicConfig(
        level=logging.INFO,
        format='%(asctime)s %(levelname)s %(message)s',
        datefmt='%H:%M:%S',
    )
    log.info('Starting — waiting %ds for services to initialise', STARTUP_DELAY)
    time.sleep(STARTUP_DELAY)
    Discoverer().run()
