# Runner de QA: ejecuta la colección Postman contra la API por la red privada de Railway.
# No sirve HTTP: el servicio qa-newman corre una vez y termina (restart policy NEVER).
FROM node:22-alpine
RUN apk add --no-cache openssl && npm install -g newman@6
WORKDIR /qa
COPY qa/ /qa/
CMD ["node", "/qa/run.js"]
