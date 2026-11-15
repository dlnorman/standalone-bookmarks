<!doctype html>
<html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Privacy checks</title>
<style>body{font:16px system-ui;max-width:900px;margin:40px auto;padding:20px;background:#f5f7fa;color:#172b4d}button,select,textarea{font:inherit;padding:10px;margin:6px 0}textarea,pre{box-sizing:border-box;width:100%}pre{white-space:pre-wrap;background:#fff;padding:20px;overflow-wrap:anywhere}button{cursor:pointer}a{color:#125ab5}</style>
<h1>Privacy checks</h1><p>Signed in as <?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?>. <a href="index.php">Back to account</a></p>
<p>Only your stored username and display name are exported. Shared bookmarks have no user ownership field and are excluded. Consent is saved. Other operations return decisions and proposed action plans; account updates, deletions, notifications and complaints are not executed.</p>
<p>Breach assessment requires the original application administrator role, checked against the current database account.</p>
<button id="grant">Grant consent</button> <button id="withdraw">Withdraw consent</button>
<p><label>Operation <select id="policy"><?php foreach (['consent','lawful','information','access','rectification','erasure','restriction','portability','objection','complaint','breach'] as $operation): ?><option><?= $operation ?></option><?php endforeach ?></select></label></p>
<label for="body">Request JSON</label><textarea id="body" rows="7">{}</textarea><br><button id="evaluate">Evaluate</button>
<pre id="output" role="status">Choose an operation.</pre>
<script>
const token=<?= json_encode($csrf, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const policy=document.querySelector('#policy'),body=document.querySelector('#body'),output=document.querySelector('#output');
const examples={rectification:{updates:{username:'UpdatedName'}},erasure:{fields:['username'],reason:'withdrawn_consent'},complaint:{authority_id:'cy-0000',description:'Example complaint; no message is sent'},breach:{incident_id:'example-incident'}};
policy.onchange=()=>body.value=JSON.stringify(examples[policy.value]||{},null,2);
async function send(operation,value,method='POST'){try{const r=await fetch('gdpr.php?policy='+encodeURIComponent(operation),{method,headers:{'Content-Type':'application/json','X-CSRF-Token':token},body:JSON.stringify(value)});output.textContent='HTTP '+r.status+'\n'+JSON.stringify(await r.json(),null,2)}catch(e){output.textContent=e.message}}
document.querySelector('#evaluate').onclick=()=>{try{send(policy.value,JSON.parse(body.value))}catch(e){output.textContent=e.message}};
document.querySelector('#grant').onclick=()=>send('consent',{value:'yes',withdrawn:false},'PATCH');
document.querySelector('#withdraw').onclick=()=>send('consent',{value:'yes',withdrawn:true},'PATCH');
</script></html>
