<?php
// index.php
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8" />
<title>Flood Check — 3D MAP </title>
<meta name="viewport" content="width=device-width,initial-scale=1" />
<link href="https://unpkg.com/maplibre-gl@2.4.0/dist/maplibre-gl.css" rel="stylesheet" />
<style>
html,body{height:100%;margin:0;padding:0;font-family:system-ui,Segoe UI,Roboto,Arial;}
#map{position:absolute;inset:0}
.panel{
  position:absolute;left:12px;top:12px;z-index:10;
  background:rgba(255,255,255,0.95);padding:12px;border-radius:10px;
  box-shadow:0 8px 20px rgba(0,0,0,0.12);width:360px;
}
.row{display:flex;gap:8px;align-items:center;margin:8px 0;}
input[type="text"]{flex:1;padding:8px;border:1px solid #ddd;border-radius:8px}
button{padding:8px 10px;border-radius:8px;border:0;background:#0b63d6;color:#fff;cursor:pointer}
button.secondary{background:#e8eaed;color:#111}
.status{font-size:13px;color:#333;margin-top:6px;}
small.muted{display:block;color:#666;margin-top:6px}
</style>
</head>
<body>
<div id="map"></div>
<div class="panel">
<div style="font-weight:800;margin-bottom:6px">🗺️ Flood Check</div>
<div class="row">
  <label style="min-width:110px">ค้นหาพื้นที่</label>
  <input id="search" type="text" placeholder="ค้นหาพื้นที่น้ำท่วม" />
  <button id="btnSearch">🔍</button>
</div>
<div class="row">
  <button id="btnLocate" class="secondary">📍 ตำแหน่งของฉัน</button>
  <button id="btnTilt">⛰ สลับเอียงแผนที่</button>
</div>
<div class="status" id="status">สถานะ: รอการค้นหา</div>
<small class="muted">W/A/S/D = เคลื่อนที่, Arrow = หมุน/เอียง, Tilt = กล้องเอียง 3D</small>
</div>

<script src="https://unpkg.com/maplibre-gl@2.4.0/dist/maplibre-gl.js"></script>
<script src="https://unpkg.com/@turf/turf@6/turf.min.js"></script>
<script>
const map=new maplibregl.Map({
  container:'map',
  style:{version:8,sources:{'osm-tiles':{type:'raster',tiles:['https://a.tile.openstreetmap.org/{z}/{x}/{y}.png','https://b.tile.openstreetmap.org/{z}/{x}/{y}.png','https://c.tile.openstreetmap.org/{z}/{x}/{y}.png'],tileSize:256,attribution:'© OpenStreetMap contributors'}},layers:[{id:'osm-tiles',type:'raster',source:'osm-tiles'}]},
  center:[100.5018,13.7563],
  zoom:12,
  pitch:45,
  bearing:0,
  attributionControl:true
});

const btnSearch=document.getElementById('btnSearch');
const searchInput=document.getElementById('search');
const btnLocate=document.getElementById('btnLocate');
const btnTilt=document.getElementById('btnTilt');
const statusEl=document.getElementById('status');
let tilted=true;

function setStatus(txt,severity='info'){statusEl.textContent='สถานะ: '+txt;if(severity==='warn') statusEl.style.color='#b22222';else if(severity==='ok') statusEl.style.color='#137333';else statusEl.style.color='#222';}

async function searchLocation(query){
  try{
    setStatus('กำลังค้นหาพื้นที่...');
    const response=await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}`);
    const data=await response.json();
    if(data.length===0) throw new Error('ไม่พบพื้นที่ที่ค้นหา');
    const {lat, lon}=data[0];
    map.easeTo({center:[lon, lat], zoom:14});
    loadGeoJSON(lat, lon);
  }catch(e){setStatus('ไม่สามารถค้นหาพื้นที่ได้: '+e.message,'warn');}
}
async function loadGeoJSON(lat, lon){
  try{
    setStatus('กำลังโหลดข้อมูล GeoJSON...');
    const overpass=`https://overpass-api.de/api/interpreter?data=[out:json];(way["natural"="water"](around:5000,${lat},${lon}););out;`;
    const r=await fetch(overpass);
    const d=await r.json();
    const features=d.elements.filter(el=>el.geometry).map(el=>({type:'Feature',geometry:{type:'Polygon',coordinates:[el.geometry.map(p=>[p.lon,p.lat])]},properties:{}}));
    const geojson={type:'FeatureCollection',features:features};
    if(map.getLayer('flood-fill')) map.removeLayer('flood-fill');
    if(map.getLayer('flood-line')) map.removeLayer('flood-line');
    if(map.getSource('flood-geojson')) map.removeSource('flood-geojson');
    map.addSource('flood-geojson',{type:'geojson',data:geojson});
    map.addLayer({id:'flood-fill',type:'fill',source:'flood-geojson',paint:{'fill-color':'#1E90FF','fill-opacity':0.35}});
    map.addLayer({id:'flood-line',type:'line',source:'flood-geojson',paint:{'line-color':'#1E90FF','line-width':2}});
    setStatus('โหลดข้อมูล GeoJSON สำเร็จ','ok');
  }catch(e){setStatus('ไม่สามารถโหลดข้อมูล GeoJSON ได้: '+e.message,'warn');}
}

function toggleTilt(){tilted=!tilted;map.easeTo({pitch:tilted?45:0,bearing:tilted?20:0,duration:800});}

btnSearch.addEventListener('click',()=>{const q=searchInput.value.trim();if(q) searchLocation(q);});
btnLocate.addEventListener('click',()=>{
  if(!navigator.geolocation){alert('เบราว์เซอร์ไม่รองรับ Geolocation');return;}
  navigator.geolocation.getCurrentPosition(pos=>{
    const {latitude,longitude}=pos.coords;
    map.easeTo({center:[longitude,latitude],zoom:14});
    loadGeoJSON(latitude,longitude);
  },err=>{alert('ไม่สามารถระบุตำแหน่ง: '+err.message);});
});
btnTilt.addEventListener('click',toggleTilt);

document.addEventListener('keydown',e=>{
  const step=0.01; const rot=5; const pitchStep=5;
  switch(e.key.toLowerCase()){
    case'w':map.easeTo({center:[map.getCenter().lng,map.getCenter().lat+step]});break;
    case's':map.easeTo({center:[map.getCenter().lng,map.getCenter().lat-step]});break;
    case'a':map.easeTo({center:[map.getCenter().lng-step,map.getCenter().lat]});break;
    case'd':map.easeTo({center:[map.getCenter().lng+step,map.getCenter().lat]});break;
    case'arrowup':map.easeTo({pitch:map.getPitch()+pitchStep});break;
    case'arrowdown':map.easeTo({pitch:map.getPitch()-pitchStep});break;
    case'arrowleft':map.easeTo({bearing:map.getBearing()-rot});break;
    case'arrowright':map.easeTo({bearing:map.getBearing()+rot});break;
  }
});

map.on('load',()=>{setStatus('พร้อมใช้งาน — ค้นหาพื้นที่น้ำท่วมโดยพิมพ์ชื่อและกดค้นหา');});
</script>
</body>
</html>
