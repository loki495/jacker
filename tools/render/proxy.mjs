// Filtering forward proxy for the renderer: Chromium sends every request, redirect, WebSocket and
// subresource through it, and each connection goes to an address the vetting callback returned,
// so a hostname cannot be re-resolved to a private address after it was checked.
import http from 'node:http';
import net from 'node:net';

// ponytail: connects to the first vetted address only; try the rest if hosts with dead A records matter.
export function startProxy(vet) {
    const bare = (host) => host.replace(/^\[|\]$/g, '');
    const server = http.createServer(async (req, res) => {
        let url;
        try {
            url = new URL(req.url);
        } catch {
            return res.writeHead(400).end();
        }
        if (url.protocol !== 'http:') return res.writeHead(403).end();
        let addresses;
        try {
            addresses = await vet(bare(url.hostname));
        } catch {
            return res.writeHead(403).end('blocked');
        }
        const upstream = http.request({ host: addresses[0], port: url.port || 80, method: req.method, path: url.pathname + url.search, headers: req.headers }, (reply) => {
            res.writeHead(reply.statusCode, reply.headers);
            reply.pipe(res);
        });
        upstream.on('error', () => {
            if (!res.headersSent) res.writeHead(502);
            res.end();
        });
        req.pipe(upstream);
    });
    server.on('connect', async (req, socket, head) => {
        socket.on('error', () => socket.destroy());
        const match = /^(\[.+\]|[^:]+):(\d+)$/.exec(req.url ?? '');
        let addresses;
        try {
            if (!match) throw new Error('bad target');
            addresses = await vet(bare(match[1]));
        } catch {
            return socket.end('HTTP/1.1 403 Forbidden\r\n\r\n');
        }
        const upstream = net.connect(Number(match[2]), addresses[0], () => {
            socket.write('HTTP/1.1 200 Connection Established\r\n\r\n');
            if (head.length) upstream.write(head);
            upstream.pipe(socket);
            socket.pipe(upstream);
        });
        upstream.on('error', () => socket.end('HTTP/1.1 502 Bad Gateway\r\n\r\n'));
        socket.on('close', () => upstream.destroy());
    });

    return new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve(server)));
}
