#!/bin/sh
# Prima installazione su un server Ubuntu nuovo (Oracle Cloud, Google Cloud…), dalla cartella del progetto:
#   ./infrastructure/scripts/install.sh tuonome.duckdns.org
# Si può rilanciare senza danni: non rigenera il .env e non tocca i dati.
# Guide in docs/DEPLOY-GOOGLE-CLOUD.md e docs/DEPLOY-ORACLE.md.
set -eu
DOMAIN="${1:?Uso: install.sh tuonome.duckdns.org}"
cd "$(dirname "$0")/../.."

echo "== Firewall interno di Ubuntu: porte 80 e 443 (solo sulle immagini Oracle, che bloccano tutto tranne SSH)"
if command -v iptables >/dev/null 2>&1 \
  && sudo iptables -S INPUT | grep -q -- '-j REJECT' \
  && ! sudo iptables -S INPUT | grep -q -- '--dport 443'; then
  sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 80 -j ACCEPT
  sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 443 -j ACCEPT
  sudo netfilter-persistent save || true
fi

echo "== Docker"
if ! command -v docker >/dev/null 2>&1; then
  curl -fsSL https://get.docker.com | sudo sh
fi
sudo usermod -aG docker "$(id -un)"

echo "== File .env"
if [ ! -f .env ]; then
  cp .env.production.example .env
  sed -i \
    -e "s#CAMBIA-tuonome.duckdns.org#${DOMAIN}#g" \
    -e "s#CAMBIA-con-openssl-rand-hex-32#$(openssl rand -hex 32)#" \
    -e "s#CAMBIA-con-un-altra-openssl-rand-hex-24#$(openssl rand -hex 24)#" \
    -e "s#CAMBIA-con-openssl-rand-hex-24#$(openssl rand -hex 24)#" \
    -e "s#CAMBIA-codice-invito#$(openssl rand -hex 8)#" .env
  chmod 600 .env
  echo "   creato con chiavi e password nuove"
else
  echo "   già presente, lasciato com'è"
fi

echo "== Frontend"
sudo docker run --rm -v "$PWD/frontend:/app" -w /app node:20-alpine sh -c "npm ci --no-audit --no-fund && npx ng build"

echo "== Avvio dei container"
sudo docker compose up -d --build

echo "== Backup ogni notte alle 3"
mkdir -p "$HOME/backup-marketing-os"
chmod +x infrastructure/scripts/*.sh
LINE="0 3 * * * $PWD/infrastructure/scripts/backup.sh >> $HOME/backup-marketing-os/backup.log 2>&1"
(crontab -l 2>/dev/null | grep -v 'scripts/backup.sh'; echo "$LINE") | crontab -

echo "== Attendo il certificato HTTPS e l'API (fino a 5 minuti)"
i=0
until curl -fsS "https://${DOMAIN}/api/v1/health" >/dev/null 2>&1; do
  i=$((i + 1))
  if [ "$i" -ge 60 ]; then
    echo "   L'API non risponde ancora: controlla con 'docker compose logs caddy app'." >&2
    exit 1
  fi
  sleep 5
done

echo
echo "Fatto: https://${DOMAIN}"
echo "Codice invito per registrarti: $(grep '^REGISTRATION_INVITE_CODE=' .env | cut -d= -f2)"
echo "Poi: docker compose exec app php bin/make-admin.php tua-email@esempio.it"
