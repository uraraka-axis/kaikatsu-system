const pptxgen = require('pptxgenjs');
const path = require('path');
const html2pptx = require(path.join(process.env.HOME || process.env.USERPROFILE, '.claude/skills/pptx/scripts/html2pptx'));

const slidesDir = path.join(__dirname, 'slides');
const outputFile = path.join(__dirname, 'wireframes.pptx');

const slides = [
  'slide01-cover.html',
  'slide02-screens.html',
  'slide03-login.html',
  'slide04-menu.html',
  'slide05-repair.html',
  'slide06-equipment.html',
  'slide07-parts.html',
  'slide08-orderlist-store.html',
  'slide09-orderlist-admin.html',
  'slide10-budget.html',
  'slide11-admin-menu.html',
  'slide12-history.html',
  'slide13-settings.html',
];

async function build() {
  const pptx = new pptxgen();
  pptx.layout = 'LAYOUT_16x9';
  pptx.author = 'Kaikatsu Frontier';
  pptx.title = '快活フロンティア 発注管理システム ワイヤーフレーム';

  for (const file of slides) {
    const htmlPath = path.join(slidesDir, file);
    console.log('Processing:', file);
    await html2pptx(htmlPath, pptx);
  }

  await pptx.writeFile({ fileName: outputFile });
  console.log('Done:', outputFile);
}

build().catch(e => { console.error(e); process.exit(1); });
