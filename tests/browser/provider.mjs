// Deterministic local provider. Does not forward requests or use real credentials.
import { createServer } from 'node:http';
import { readFileSync } from 'node:fs';
const file = process.env.BROWSER_PROVIDER_FIXTURES;
if (!file) throw new Error('Set BROWSER_PROVIDER_FIXTURES to the JSON emitted by seed.php.');
const fixtures = JSON.parse(readFileSync(file, 'utf8'));
createServer(async (request, response) => {
    response.setHeader('Content-Type', 'application/json');
    if (request.method === 'GET') {
        response.end(JSON.stringify({ data: [{ id: 'browser-fixture-model' }] }));
        return;
    }
    try {
        let body = '';
        for await (const chunk of request) {
            body += chunk;
            if (body.length > 2_000_000) throw new Error('Request too large');
        }
        const data = JSON.parse(body);
        const system = data.messages.filter(message => message.role === 'system').map(message => message.content).join('\n');
        let kind = system.includes('STORY BATCH CONTRACT') ? 'long' : 'short';
        if (system.includes('AUTHORITATIVE OPENING INHERITANCE')) kind = 'next';
        if (system.includes('The user chose END STORY.')) kind = 'ending';
        response.end(JSON.stringify({ choices: [{ message: { content: fixtures[kind] } }] }));
    } catch {
        response.statusCode = 400;
        response.end(JSON.stringify({ error: 'Invalid browser fixture request' }));
    }
}).listen(8901, '127.0.0.1', () => console.log('Local browser provider ready on port 8901'));
