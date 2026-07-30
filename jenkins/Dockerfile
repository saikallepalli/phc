FROM jenkins/jenkins:lts-jdk17

USER root

# Docker CLI + compose v2 plugin, so `docker` and `docker compose` work inside
# this container against the host daemon via the mounted socket.
# Installing them here means they survive container recreation — unlike
# `docker exec ... apt-get install`, which is lost on every rebuild.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl gnupg lsb-release \
    && install -m 0755 -d /etc/apt/keyrings \
    && curl -fsSL https://download.docker.com/linux/debian/gpg \
        | gpg --dearmor -o /etc/apt/keyrings/docker.gpg \
    && chmod a+r /etc/apt/keyrings/docker.gpg \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
        https://download.docker.com/linux/debian $(lsb_release -cs) stable" \
        > /etc/apt/sources.list.d/docker.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends docker-ce-cli docker-compose-plugin \
    && rm -rf /var/lib/apt/lists/*

# Pre-install the plugins this pipeline needs, so first boot is ready to go.
RUN jenkins-plugin-cli --plugins \
      workflow-aggregator \
      git \
      github \
      github-branch-source \
      credentials-binding \
      timestamps \
      ws-cleanup

# Running as root is the simplest way to get access to the mounted docker
# socket on Docker Desktop. For anything beyond local dev, add the jenkins
# user to a group matching the socket's GID instead.
USER root
