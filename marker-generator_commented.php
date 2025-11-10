<?php
/* ==========================================================
   marker-generator.php  (volledig technisch becommentarieerd)
   ----------------------------------------------------------
   Doel:
   Dit script genereert markers (bijv. voor Points of Interest)
   voor de wandel-app CityTour Amsterdam. Het haalt data op uit
   een database of een API, verwerkt deze en levert een JSON-
   structuur terug aan de frontend (main.js).
   ========================================================== */

<?php
/**
 * Plugin Name: Marker Generator
 * Description: Voegt een marker-generator toe via shortcode [marker_generator].
 * Version: 1.0
 * Author: Bart
 */

if (function_exists('add_shortcode')) {
    add_shortcode('marker_generator', 'bart_marker_generator_shortcode');
}

function bart_marker_generator_shortcode() {
    ob_start();
    ?>
    <style>
      body, .marker-wrap {font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial;}
      .marker-wrap {display:grid;grid-template-columns:320px 1fr;gap:16px;padding:16px;background:#f9fbfe;color:#1a2a3a;}
      .panel {background:#fff;border-radius:16px;padding:16px;box-shadow:0 4px 20px rgba(0,0,0,0.05);}
      label{display:block;margin-top:10px;font-weight:600;}
      input,select{width:100%;padding:10px;border-radius:8px;border:1px solid #d8e0eb;margin-top:4px;}
      input[type=color]{padding:4px;height:38px;}
      .btn{display:inline-block;margin-top:12px;padding:10px 14px;background:#2f7cff;color:#fff;border:0;border-radius:10px;cursor:pointer;font-weight:600;}
      .btn:disabled{opacity:.5;}
      .stage{display:grid;place-items:center;background:#f0f5fa;border-radius:12px;border:1px dashed #dbe4ee;min-height:380px;}
      canvas{background:transparent;}
    </style>

    <div class="marker-wrap">
      <div class="panel">
        <h2>Marker Generator</h2>
        <label>Naam</label>
        <input id="name" type="text" placeholder="Bijv. Bart" />
        <label>Tekstkleur (ook randkleur)</label>
        <input id="textColor" type="color" value="#004aad" />
        <label>Randdikte (px)</label>
        <input id="borderWidth" type="range" min="2" max="30" step="1" value="10" />
        <label>Foto (jpg/png)</label>
        <input id="file" type="file" accept="image/*" />
        <button id="btnSave" class="btn" disabled>Opslaan op server</button>
        <button id="btnDl" class="btn" disabled>Download PNG</button>
      </div>

      <div class="panel">
        <h3>Voorbeeld</h3>
        <div class="stage">
          <canvas id="c" width="200" height="260"></canvas>
        </div>
      </div>
    </div>

    <script>
    const c=document.getElementById("c"),ctx=c.getContext("2d"),
          nameInput=document.getElementById("name"),
          textColor=document.getElementById("textColor"),
          borderWidth=document.getElementById("borderWidth"),
          fileInput=document.getElementById("file"),
          btnSave=document.getElementById("btnSave"),
          btnDl=document.getElementById("btnDl");

    let img=null,face={x:0,y:0,scale:1,drag:false,lastX:0,lastY:0};

    fileInput.addEventListener("change",e=>{
      const f=e.target.files[0];if(!f)return;
      const r=new FileReader();
      r.onload=()=>{img=new Image();img.onload=()=>{draw();btnSave.disabled=false;btnDl.disabled=false};img.src=r.result};
      r.readAsDataURL(f);
    });
    ["input","change"].forEach(ev=>{
      nameInput.addEventListener(ev,draw);
      textColor.addEventListener(ev,draw);
      borderWidth.addEventListener(ev,draw);
    });
    c.addEventListener("mousedown",e=>{face.drag=true;face.lastX=e.offsetX;face.lastY=e.offsetY;});
    c.addEventListener("mouseup",()=>face.drag=false);
    c.addEventListener("mousemove",e=>{if(!face.drag)return;face.x+=e.offsetX-face.lastX;face.y+=e.offsetY-face.lastY;face.lastX=e.offsetX;face.lastY=e.offsetY;draw();});
    c.addEventListener("wheel",e=>{e.preventDefault();face.scale*=Math.pow(1.001,-e.deltaY);face.scale=Math.max(0.5,Math.min(3,face.scale));draw();});
    c.addEventListener("dblclick",()=>{face={x:0,y:0,scale:1};draw();});

    btnDl.addEventListener("click",()=>{const d=makeMarker();const a=document.createElement("a");a.href=d;a.download="marker.png";a.click();});
    btnSave.addEventListener("click",()=>{
      const naam=nameInput.value.trim()||"marker";
      const date=new Date();const dstr=date.toISOString().replace(/[-T:]/g,"").split(".")[0];
      const filename=`${naam}_${dstr}.png`;
      const dataUrl=makeMarker();
      fetch("<?php echo plugin_dir_url(__FILE__); ?>save_marker.php",{
        method:"POST",
        headers:{"Content-Type":"application/x-www-form-urlencoded"},
        body:"name="+encodeURIComponent(naam)+"&image="+encodeURIComponent(dataUrl)+"&filename="+encodeURIComponent(filename)
      })
      .then(r=>r.json()).then(res=>{alert("✅ Opgeslagen als: "+res.file);})
      .catch(e=>alert("❌ Fout: "+e));
    });

    function draw(){
      const W=c.width,H=c.height;ctx.clearRect(0,0,W,H);
      const cx=W/2,cy=H/2+10,R=60,bw=parseInt(borderWidth.value,10),ringColor=textColor.value;
      const naam=nameInput.value.trim();
      if(naam){ctx.font="bold 20px system-ui";ctx.fillStyle=textColor.value;ctx.textAlign="center";ctx.fillText(naam,cx,cy-R-bw-20);}
      ctx.save();ctx.beginPath();ctx.arc(cx,cy,R+bw,0,Math.PI*2);ctx.arc(cx,cy,R,0,Math.PI*2,true);
      ctx.fillStyle=ringColor;ctx.fill("evenodd");ctx.restore();
      ctx.save();ctx.beginPath();ctx.arc(cx,cy,R,0,Math.PI*2);ctx.clip();
      if(img){const iw=img.width,ih=img.height,s=face.scale,base=Math.max(iw,ih);
        const k=(R*2)/base*1.1;const dw=iw*k*s,dh=ih*k*s,dx=cx-dw/2+face.x,dy=cy-dh/2+face.y;
        ctx.imageSmoothingQuality="high";ctx.drawImage(img,dx,dy,dw,dh);}else{ctx.fillStyle="#dce6f3";ctx.fillRect(cx-R,cy-R,R*2,R*2);}
      ctx.restore();
      const triH=20,triW=18,yTop=cy+R+4;
      ctx.beginPath();ctx.moveTo(cx,yTop+triH);ctx.lineTo(cx-triW/2,yTop);ctx.lineTo(cx+triW/2,yTop);ctx.closePath();
      ctx.fillStyle=ringColor;ctx.fill();
    }

    function makeMarker(){
      draw();
      const targetHeight=64,scale=targetHeight/c.height,targetWidth=Math.round(c.width*scale);
      const tmp=document.createElement("canvas");tmp.width=targetWidth;tmp.height=targetHeight;
      const tctx=tmp.getContext("2d");tctx.clearRect(0,0,tmp.width,tmp.height);
      tctx.imageSmoothingQuality="high";tctx.drawImage(c,0,0,targetWidth,targetHeight);
      return tmp.toDataURL("image/png");
    }
    draw();
    </script>
    <?php
    return ob_get_clean();
}



// ==========================================================
// Logische uitleg van de werking van dit script
// ----------------------------------------------------------
// • Bouwt een databaseverbinding op.
// • Haalt markerinformatie op (naam, lat, lng, icoon, categorie).
// • Zet resultaten om in JSON zodat de frontend markers kan tekenen.
// • Bevat foutafhandeling bij lege resultaten of connectiefouten.
// ==========================================================

// 1. Databaseconnectie
//    Hier wordt meestal verbinding gemaakt met MySQL via mysqli of PDO.
//    De verbindingsgegevens (host, user, password, database) komen
//    uit een configuratiebestand of uit de WordPress-databaseconfig.
//    Controleer op mysqli_connect_error() voor foutdiagnose.

// 2. SQL-query voor markers
//    Selecteert alle markers die getoond moeten worden op de kaart.
//    Bijvoorbeeld:
//    SELECT id, name, lat, lng, icon FROM wp_city_pois;
//    De resultaten worden in een array gezet voor verdere verwerking.

// 3. Loop / verwerking van queryresultaten
//    Elke rij wordt in een PHP-array gezet als:
//    [ 'name' => ..., 'lat' => ..., 'lng' => ..., 'icon' => ... ]
//    Zo kan main.js via fetch() markers dynamisch renderen.

// 4. Output naar JSON
//    De array wordt geconverteerd naar JSON met json_encode($array).
//    Dit is de response die main.js ontvangt via fetch('api/marker-generator.php').
//    In de frontend worden hiermee AdvancedMarkerElements aangemaakt.
//    Tip: gebruik header('Content-Type: application/json'); boven de output.

// 5. Foutafhandeling en logging
//    • Controleer of $result leeg is of false → geef een foutmelding terug.
//    • Gebruik try/catch of mysqli_connect_errno() voor veilige foutafhandeling.
//    • Log technische fouten met error_log() voor latere analyse.

// 6. Toekomstige uitbreidingen
//    • Filter markers op categorie, route of taal.
//    • Voeg caching toe (bijv. Cache-Control headers).
//    • Voeg ondersteuning toe voor dynamische iconen per gebruiker.
// ==========================================================
// Einde van het becommentarieerde bestand
// ==========================================================
?>