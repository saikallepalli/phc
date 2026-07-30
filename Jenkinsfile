pipeline {
  agent any

  options {
    timestamps()
    buildDiscarder(logRotator(numToKeepStr: '20'))
    timeout(time: 20, unit: 'MINUTES')
    disableConcurrentBuilds()
  }

  environment {
    IMAGE      = 'phc-web'
    TAG        = "${env.BUILD_NUMBER}"
    DEPLOYMENT = 'phc-web'
    NODE_PORT  = '30082'
    // Jenkins runs in a container; the cluster API and NodePort are on the host.
    HOST       = 'host.docker.internal'
  }

  stages {

    stage('Checkout') {
      steps {
        checkout scm
        sh 'git rev-parse --short HEAD > .gitsha && cat .gitsha'
      }
    }

    stage('Security gate') {
      steps {
        sh 'chmod +x scripts/security-gate.sh && ./scripts/security-gate.sh'
      }
    }

    stage('Build image') {
      steps {
        sh '''
          docker build \
            --label "git.sha=$(cat .gitsha)" \
            --label "jenkins.build=$BUILD_NUMBER" \
            -t $IMAGE:$TAG -t $IMAGE:latest .
        '''
      }
    }

    stage('Verify image contents') {
      steps {
        // The image must not contain the compose file, .git, or the Jenkinsfile.
        sh '''
          leaked=$(docker run --rm --entrypoint sh $IMAGE:$TAG -c \
            'ls -A /var/www/html | grep -E "^(docker-compose\\.ya?ml|\\.git|Jenkinsfile|\\.env|k8s|scripts)$" || true')
          if [ -n "$leaked" ]; then
            echo "Image ships files that must not be web-served:"
            echo "$leaked"
            exit 1
          fi
          echo "Image contents OK."
        '''
      }
    }

    stage('Sync DB secret') {
      steps {
        // Credentials live in Jenkins, not in the repo. Create these once under
        // Manage Jenkins > Credentials as "Secret text" entries.
        withCredentials([
          string(credentialsId: 'phc-db-host', variable: 'DB_HOST'),
          string(credentialsId: 'phc-db-name', variable: 'DB_NAME'),
          string(credentialsId: 'phc-db-user', variable: 'DB_USER'),
          string(credentialsId: 'phc-db-pass', variable: 'DB_PASS')
        ]) {
          sh '''
            set +x
            kubectl create secret generic phc-db \
              --from-literal=DB_HOST="$DB_HOST" \
              --from-literal=DB_PORT="3306" \
              --from-literal=DB_NAME="$DB_NAME" \
              --from-literal=DB_USER="$DB_USER" \
              --from-literal=DB_PASS="$DB_PASS" \
              --dry-run=client -o yaml | kubectl apply -f - >/dev/null
            echo "Secret phc-db synced."
          '''
        }
      }
    }

    stage('Deploy') {
      steps {
        sh '''
          kubectl apply -f k8s/web.yaml
          kubectl set image deployment/$DEPLOYMENT web=$IMAGE:$TAG --record=false
          kubectl rollout status deployment/$DEPLOYMENT --timeout=180s
        '''
      }
    }

    stage('Smoke test') {
      steps {
        sh '''
          set -e
          base="http://$HOST:$NODE_PORT"

          # 1. Static frontend loads.
          curl -fsS -o /dev/null "$base/index.html"
          echo "  ok  index.html"

          # 2. API answers and returns JSON, proving the DB connection works.
          #    (a 500 with "Database connection failed" fails this check)
          body=$(curl -fsS "$base/api.php?_p=api/pilots")
          echo "$body" | head -c 200 | grep -q '^\\[' || {
            echo "  FAIL  /api/pilots did not return a JSON array:"; echo "$body" | head -c 300; exit 1; }
          echo "  ok  /api/pilots"

          # 3. Protected route must reject an anonymous caller.
          code=$(curl -s -o /dev/null -w '%{http_code}' -X DELETE "$base/api.php?_p=api/phcs/__smoke__")
          [ "$code" = "401" ] || { echo "  FAIL  unauthenticated DELETE returned $code, expected 401"; exit 1; }
          echo "  ok  auth enforced on DELETE"

          # 4. Secrets must not be reachable over HTTP.
          for path in docker-compose.yml .env Jenkinsfile .git/config; do
            code=$(curl -s -o /dev/null -w '%{http_code}' "$base/$path")
            [ "$code" = "404" ] || [ "$code" = "403" ] || {
              echo "  FAIL  $path is publicly readable (HTTP $code)"; exit 1; }
          done
          echo "  ok  no secret files served"
        '''
      }
    }
  }

  post {
    failure {
      script {
        // Only roll back if the failure happened at or after deployment.
        sh '''
          if kubectl get deployment/$DEPLOYMENT >/dev/null 2>&1; then
            current=$(kubectl get deployment/$DEPLOYMENT -o jsonpath='{.spec.template.spec.containers[0].image}')
            if [ "$current" = "$IMAGE:$TAG" ]; then
              echo "Rolling back $DEPLOYMENT"
              kubectl rollout undo deployment/$DEPLOYMENT || true
              kubectl rollout status deployment/$DEPLOYMENT --timeout=120s || true
            fi
          fi
        '''
        sh 'kubectl describe deployment/$DEPLOYMENT || true'
        sh 'kubectl logs -l app=$DEPLOYMENT --tail=80 --all-containers || true'
      }
    }
    success {
      echo "Deployed $IMAGE:$TAG — http://localhost:${env.NODE_PORT}"
    }
    always {
      // Keep the last few tags, drop the rest.
      sh '''
        docker images "$IMAGE" --format '{{.Tag}}' \
          | grep -E '^[0-9]+$' | sort -rn | tail -n +6 \
          | xargs -r -I{} docker rmi "$IMAGE:{}" || true
      '''
    }
  }
}
