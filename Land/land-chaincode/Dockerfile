# SPDX-License-Identifier: Apache-2.0
FROM node:22 AS builder

WORKDIR /usr/src/app

COPY --chown=node:node . /usr/src/app
ENV npm_config_cache=/usr/src/app
RUN npm ci

FROM node:22 AS production
ARG CC_SERVER_PORT

ENV TINI_VERSION v0.19.0
ENV PLATFORM=amd64
ADD https://github.com/krallin/tini/releases/download/${TINI_VERSION}/tini-${PLATFORM} /tini
RUN chmod +x /tini

WORKDIR /usr/src/app
COPY --chown=node:node --from=builder /usr/src/app .
COPY --chown=node:node docker/docker-entrypoint.sh /usr/src/app/docker-entrypoint.sh

RUN npm ci --omit=dev && npm cache clean --force

ENV PORT $CC_SERVER_PORT
EXPOSE $CC_SERVER_PORT
ENV NODE_ENV=production

USER node
ENTRYPOINT [ "/tini", "--", "/usr/src/app/docker-entrypoint.sh" ]

