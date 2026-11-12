import {createHmac} from 'node:crypto'
import {setTimeout as delay} from 'node:timers/promises'
/** Each real login/step-up consumes a TOTP counter. Wait for the NEXT slot, including across workers. */
export async function freshAdminTotp():Promise<string> {
  const seed=process.env.E2E_ADMIN_TOTP_SECRET
  if(!seed)return process.env.E2E_ADMIN_MFA||''
  const alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';let bits=0,buffer=0;const bytes:number[]=[]
  for(const c of seed.toUpperCase().replace(/[\s=]/g,'')){const n=alphabet.indexOf(c);if(n<0)throw new Error('Invalid E2E TOTP seed');buffer=(buffer<<5)|n;bits+=5;if(bits>=8){bits-=8;bytes.push((buffer>>>bits)&255)}}
  if(bytes.length<10)throw new Error('Invalid E2E TOTP seed')
  await delay(30000-Date.now()%30000+1200)
  const counter=Buffer.alloc(8);counter.writeBigUInt64BE(BigInt(Math.floor(Date.now()/30000)))
  const h=createHmac('sha1',Buffer.from(bytes)).update(counter).digest(),offset=h[h.length-1]!&15
  return String((h.readUInt32BE(offset)&0x7fffffff)%1000000).padStart(6,'0')
}
