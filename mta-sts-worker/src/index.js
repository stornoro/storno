const POLICY = `version: STSv1
mode: testing
mx: inbound-smtp.eu-west-1.amazonaws.com
mx: *.amazonaws.com
max_age: 86400
`;

export default {
  async fetch(request) {
    const url = new URL(request.url);
    if (url.pathname === '/.well-known/mta-sts.txt') {
      return new Response(POLICY, {
        headers: {
          'content-type': 'text/plain; charset=utf-8',
          'cache-control': 'public, max-age=86400',
        },
      });
    }
    return new Response('Not Found', { status: 404 });
  },
};
