/**
 * Install the official pinned Oracle JET 21 VDOM starter and overlay ATLAS.
 * This intentionally does not commit generated vendor or template files.
 */
import { spawnSync } from "node:child_process";
import { cpSync, existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
const here=dirname(fileURLToPath(import.meta.url));
const root=resolve(here,"..");
const app=resolve(root,"atlas-jet-gmao");
const src=resolve(root,"overrides");
if(!existsSync(resolve(app,"oraclejetconfig.json"))){
  const command=process.platform==="win32"?"npx.cmd":"npx";
  const result=spawnSync(command,["--yes","@oracle/ojet-cli@21.0.0","create","atlas-jet-gmao","--template=basic","--vdom"],
    {cwd:root,stdio:"inherit",shell:process.platform==="win32"});
  if(result.status!==0)process.exit(result.status??1);
}
if(!existsSync(resolve(app,"oraclejetconfig.json")))throw Error("Official Oracle JET generator failed.");
for(const path of ["src/components/content/index.tsx","src/components/content/atlas-api.ts","src/styles/atlas-industrial.css"]){
  cpSync(resolve(src,path),resolve(app,path),{force:true});
}
const indexPath=resolve(app,"src/index.ts");
const original=readFileSync(indexPath,"utf8");
const inject='import "css!./styles/atlas-industrial.css";\n';
if(!original.includes(inject))writeFileSync(indexPath,inject+original);
mkdirSync(resolve(root,"reports"),{recursive:true});
console.log("Oracle JET 21 VDOM created and ATLAS overlay applied to: "+app);
