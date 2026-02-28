import { createHmac } from 'node:crypto'

export default defineEventHandler((event) => {
  const config = useRuntimeConfig(event)
  const identityToken = config.chatwootIdentityToken as string

  if (!identityToken) {
    throw createError({ statusCode: 404, message: 'Not configured' })
  }

  const query = getQuery(event)
  const identifier = query.identifier as string

  if (!identifier) {
    throw createError({ statusCode: 400, message: 'Missing identifier' })
  }

  const hash = createHmac('sha256', identityToken)
    .update(identifier)
    .digest('hex')

  return { identifier_hash: hash }
})
