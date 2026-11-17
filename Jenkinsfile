// Optional CI adapter; GitHub Actions owns publication and deployment.
pipeline {
  agent { label 'docker-linux' }
  options { disableConcurrentBuilds(); timeout(time: 40, unit: 'MINUTES'); timestamps() }
  environment { COMPOSE_PROJECT_NAME = "atlas-ci-${BUILD_NUMBER}"; WEB_PORT = '33100'; API_PORT = '38000' }
  stages {
    stage('Checkout') { steps { checkout scm } }
    stage('Test production images') { steps { sh 'bash scripts/ci.sh' } }
  }
  post {
    always {
      junit allowEmptyResults: true, testResults: 'reports/phpunit.xml'
      archiveArtifacts allowEmptyArchive: true, artifacts: 'reports/**,frontend/playwright-report/**,frontend/test-results/**'
    }
  }
}
