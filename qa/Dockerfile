# Runner de QA: ejecuta la colección Postman contra la API por la red privada de Railway.
# No sirve HTTP: el servicio qa-newman corre una vez y termina (restart policy NEVER).
FROM node:22-alpine
RUN apk add --no-cache openssl
WORKDIR /qa
COPY qa/package.json /qa/package.json
RUN npm install --omit=dev --no-audit --no-fund
COPY qa/ /qa/
CMD ["node", "/qa/run.js"]
