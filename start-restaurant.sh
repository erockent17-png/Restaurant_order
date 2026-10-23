#!/usr/bin/env sh
set -eu
cd "$(dirname "$0")"

if ! command -v node >/dev/null 2>&1 || ! command -v npm >/dev/null 2>&1; then
  echo "Node.js and npm are required. Install the current LTS release from https://nodejs.org/"
  exit 1
fi

if [ ! -d node_modules/express ]; then
  echo "Installing dependencies..."
  npm install
fi

echo "Starting Gauteng Order. Open http://localhost:3000"
npm start