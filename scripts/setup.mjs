import {readFileSync,writeFileSync,existsSync,mkdirSync} from 'node:fs';
import {randomBytes} from 'node:crypto';
import {fileURLToPath} from 'node:url';
import {resolve,dirname} from 'node:path';
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..');
const target=resolve(root,'.env');
if(!existsSync(target)){
  let env=readFileSync(resolve(root,'.env.example'),'utf8');
  for(const [key,value] of Object.entries({DEMO_PASSWORD:process.env.DEMO_PASSWORD||randomBytes(18).toString('hex'),APP_KEY:'base64:'+randomBytes(32).toString('base64'),DB_PASSWORD:randomBytes(24).toString('hex'),METRICS_TOKEN:randomBytes(24).toString('hex'),GRAFANA_PASSWORD:randomBytes(18).toString('hex')}))env=env.replace(new RegExp(`^${key}=.*$`,'m'),`${key}=${value}`);
  writeFileSync(target,env,{mode:0o600});
}
const env=readFileSync(target,'utf8');
const token=env.match(/^METRICS_TOKEN=(.+)$/m)?.[1];
if(!token)throw new Error('METRICS_TOKEN manquant dans .env');
mkdirSync(resolve(root,'infra/monitoring/secrets'),{recursive:true,mode:0o700});
writeFileSync(resolve(root,'infra/monitoring/secrets/metrics-token'),token,{mode:0o644});
console.log('Configuration locale prête. Démarrage : docker compose up --build -d');
