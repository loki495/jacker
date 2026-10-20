// Runs inside the renderer's network namespace: forwards its loopback port to the proxy's unix socket, the only way out.
// Exits when the process it serves (Chromium, which takes over the wrapper's pid) is gone.
import net from 'node:net';

const [port, socket, owner] = process.argv.slice(2);
net.createServer((client) => {
    const upstream = net.connect(socket);
    client.on('error', () => upstream.destroy());
    upstream.on('error', () => client.destroy());
    client.pipe(upstream).pipe(client);
}).listen(Number(port), '127.0.0.1');
setInterval(() => {
    try {
        process.kill(Number(owner), 0);
    } catch {
        process.exit(0);
    }
}, 1000);
