pipeline {
  agent any

  options {
    timestamps()
    buildDiscarder(logRotator(numToKeepStr: '20'))
    timeout(time: 20, unit: 'MINUTES')
  }

  environment {
    IMAGE_NAME  = 'phcdashboard'
    APP_TAG     = "${env.BUILD_NUMBER}"
    DB_HOST     = '184.168.113.203'
    DB_NAME     = 'phcdashboard'
    APP_URL     = 'http://host.docker.internal:8082'
    COMPOSE_PROJECT_NAME = 'phcdashboard'
  }

  stages {

    stage('Checkout') {
      steps {
        checkout scm
        sh 'git rev-parse --short HEAD > .git-sha && cat .git-sha'
      }
    }

    stage('Build image') {
      steps {
        sh 'docker build -t $IMAGE_NAME:$APP_TAG -t $IMAGE_NAME:latest .'
      }
    }

    stage('Lint PHP') {
      steps {
        // Runs against the code already baked into the image, so no volume
        // mount is needed — see the note about $PWD not resolving from
        // inside the Jenkins container.
        // php -l exits non-zero on a parse error, which fails the stage.
        sh '''
          docker run --rm --entrypoint sh $IMAGE_NAME:$APP_TAG -c '
            set -e
            find /var/www/html -path "*/vendor" -prune -o -name "*.php" -print0 \
              | xargs -0 -r -n1 php -l > /dev/null
            echo "No syntax errors."
          '
        '''
      }
    }

    stage('Verify no secrets in webroot') {
      steps {
        // Fails the build if .dockerignore ever regresses and secrets get baked in.
        sh '''
          LEAKED=$(docker run --rm --entrypoint sh $IMAGE_NAME:$APP_TAG -c \
            'ls -A /var/www/html | grep -E "^(\\.env|\\.git|docker-compose\\.ya?ml|Dockerfile|Jenkinsfile)$" || true')
          if [ -n "$LEAKED" ]; then
            echo "Sensitive files present in webroot:"
            echo "$LEAKED"
            exit 1
          fi
          echo "Webroot is clean."
        '''
      }
    }

    stage('Deploy') {
      steps {
        // DB_PASS comes from Jenkins credentials, not from the repo.
        withCredentials([usernamePassword(
              credentialsId: 'phc-db',
              usernameVariable: 'DB_USER',
              passwordVariable: 'DB_PASS')]) {
          sh '''
            cat > .env <<EOF
DB_HOST=${DB_HOST_VALUE}
DB_PORT=3306
DB_NAME=phcdashboard
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}
APP_TAG=${APP_TAG}
EOF
            docker compose up -d --force-recreate
            rm -f .env
          '''
        }
      }
    }

    stage('Smoke test') {
      steps {
        sh '''
          for i in $(seq 1 20); do
            CODE=$(curl -s -o /dev/null -w "%{http_code}" $APP_URL || echo 000)
            echo "attempt $i -> HTTP $CODE"
            [ "$CODE" = "200" ] && exit 0
            sleep 3
          done
          echo "App did not return HTTP 200 in time"
          docker compose logs --tail=50 web
          exit 1
        '''
      }
    }
  }

  post {
    failure {
      // Roll back to the previously good image if one exists.
      sh '''
        PREV=$(( ${APP_TAG} - 1 ))
        if docker image inspect $IMAGE_NAME:$PREV >/dev/null 2>&1; then
          echo "Rolling back to $IMAGE_NAME:$PREV"
          APP_TAG=$PREV docker compose up -d --force-recreate
        fi
      '''
    }
    always {
      sh 'rm -f .env || true'
      sh 'docker image prune -f --filter "until=168h" || true'
    }
  }
}
