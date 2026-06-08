/* Media Slider Blocks — Frontend JS (vanilla) */
(function(){
'use strict';
var lightbox;
function ensureLightbox(){
    if(lightbox)return lightbox;
    var lb=document.createElement('div');
    lb.className='msb-lightbox';
    lb.setAttribute('role','dialog');
    lb.setAttribute('aria-modal','true');
    lb.innerHTML='<div class="msb-lightbox-dialog"><button type="button" class="msb-lightbox-close" aria-label="Fermer">&times;</button><button type="button" class="msb-lightbox-prev" aria-label="Image précédente">&#8249;</button><img class="msb-lightbox-image" alt=""><button type="button" class="msb-lightbox-next" aria-label="Image suivante">&#8250;</button><div class="msb-lightbox-caption"><h3 class="msb-lightbox-title"></h3><p class="msb-lightbox-text"></p></div></div>';
    document.body.appendChild(lb);
    lightbox={el:lb,img:lb.querySelector('.msb-lightbox-image'),title:lb.querySelector('.msb-lightbox-title'),text:lb.querySelector('.msb-lightbox-text'),items:[],i:0,last:null};
    function show(i){
        var items=lightbox.items;if(!items.length)return;
        lightbox.i=(i+items.length)%items.length;
        var it=items[lightbox.i];
        lightbox.img.src=it.src;
        lightbox.img.alt=it.title||'';
        lightbox.title.textContent=it.title||'';
        lightbox.text.textContent=it.caption||'';
        lightbox.title.style.display=it.title?'':'none';
        lightbox.text.style.display=it.caption?'':'none';
        lb.classList.toggle('has-single-item',items.length<2);
    }
    lightbox.show=show;
    lightbox.open=function(items,i,trigger){lightbox.items=items;lightbox.last=trigger;show(i);lb.classList.add('is-open');document.documentElement.style.overflow='hidden';lb.querySelector('.msb-lightbox-close').focus();};
    lightbox.close=function(){lb.classList.remove('is-open');document.documentElement.style.overflow='';if(lightbox.last)lightbox.last.focus();};
    lb.querySelector('.msb-lightbox-close').addEventListener('click',lightbox.close);
    lb.querySelector('.msb-lightbox-prev').addEventListener('click',function(){show(lightbox.i-1);});
    lb.querySelector('.msb-lightbox-next').addEventListener('click',function(){show(lightbox.i+1);});
    lb.addEventListener('click',function(e){if(e.target===lb)lightbox.close();});
    document.addEventListener('keydown',function(e){
        if(!lb.classList.contains('is-open'))return;
        if(e.key==='Escape')lightbox.close();
        if(e.key==='ArrowLeft')show(lightbox.i-1);
        if(e.key==='ArrowRight')show(lightbox.i+1);
    });
    return lightbox;
}
function initAll(){document.querySelectorAll('.msb-slider-wrapper').forEach(function(w){if(w._msbInit)return;w._msbInit=true;init(w);});}
document.addEventListener('click',function(e){
    var trigger=e.target.closest('.msb-lightbox-trigger,.msb-slide-lightbox-trigger');
    if(!trigger)return;
    var wrap=trigger.closest('.msb-slider-wrapper');
    if(wrap&&wrap._msbMoved){e.preventDefault();return;}
    var src=trigger.dataset.msbFull||'';
    if(!src)return;
    var items=[{src:src,title:trigger.dataset.msbTitle||'',caption:trigger.dataset.msbCaption||''}];
    var idx=0;
    if(wrap){
        items=Array.from(wrap.querySelectorAll('.msb-lightbox-trigger,.msb-slide-lightbox-trigger')).map(function(b){
            return{src:b.dataset.msbFull||'',title:b.dataset.msbTitle||'',caption:b.dataset.msbCaption||''};
        }).filter(function(x){return x.src;});
        idx=Math.max(0,items.findIndex(function(x){return x.src===src;}));
    }else if(trigger.classList.contains('msb-core-image-lightbox-trigger')){
        items=Array.from(document.querySelectorAll('.msb-core-image-lightbox-trigger')).map(function(b){
            return{src:b.dataset.msbFull||'',title:b.dataset.msbTitle||'',caption:b.dataset.msbCaption||''};
        }).filter(function(x){return x.src;});
        idx=Math.max(0,items.findIndex(function(x){return x.src===src;}));
    }
    e.preventDefault();
    e.stopPropagation();
    ensureLightbox().open(items,idx,trigger);
},true);
document.addEventListener('keydown',function(e){
    if(e.key!=='Enter'&&e.key!==' ')return;
    var trigger=e.target.closest('.msb-lightbox-trigger,.msb-slide-lightbox-trigger');
    if(!trigger)return;
    e.preventDefault();
    trigger.click();
});
function init(w){
    var raw=w.dataset.msb;if(!raw)return;
    var cfg;try{cfg=JSON.parse(raw);}catch(e){return;}
    var se=w.querySelector('.msb-slides');
    var sl=Array.from(se.querySelectorAll('.msb-slide'));
    var tot=sl.length;if(!tot)return;
    var s={i:0,busy:false,t:null};
    var cb=[],ca=[];
    var gallery=sl.map(function(x){
        var b=x.querySelector('.msb-slide-lightbox-trigger');
        return b?{src:b.dataset.msbFull||'',title:b.dataset.msbTitle||'',caption:b.dataset.msbCaption||''}:null;
    }).filter(function(x){return x&&x.src;});
    var moved=false;

    function vis(){var x=window.innerWidth;return x<=767?cfg.slidesMobile||1:x<=980?cfg.slidesTablet||2:cfg.slidesVisible||3;}
    function metrics(){var v=vis(),g=cfg.gap||0,tw=w.querySelector('.msb-slider-track').offsetWidth,sw=(tw-g*(v-1))/v;return{v:v,g:g,sw:sw};}
    function setW(sw){se.querySelectorAll('.msb-slide').forEach(function(x){x.style.width=sw+'px';});}
    function setGap(g){se.style.gap=g+'px';}

    function setup(){
        var m=metrics();
        se.style.transition='transform '+(cfg.transitionSpeed||600)+'ms cubic-bezier(.4,0,.2,1)';
        setGap(m.g);setW(m.sw);
        if(cfg.loop)loopClones(m);
        return m;
    }

    function loopClones(m){
        cb.forEach(function(c){se.removeChild(c);}); ca.forEach(function(c){se.removeChild(c);}); cb=[];ca=[];
        var n=Math.min(m.v,tot);
        for(var i=tot-n;i<tot;i++){var c=sl[i].cloneNode(true);c.classList.add('msb-clone');se.insertBefore(c,se.firstChild);cb.unshift(c);}
        for(var j=0;j<n;j++){var d=sl[j].cloneNode(true);d.classList.add('msb-clone');se.appendChild(d);ca.push(d);}
        setW(metrics().sw); goTo(s.i,true);
    }

    function getOffset(i){var m=metrics(),n=cfg.loop?Math.min(m.v,tot):0;return-((i+n)*(m.sw+m.g));}
    function noTr(fn){se.classList.add('no-tr');fn();void se.offsetWidth;se.classList.remove('no-tr');}

    function goTo(i,fast){
        if(!fast&&s.busy)return;
        if(!cfg.loop)i=Math.max(0,Math.min(i,tot-vis()));
        s.i=i; var o=getOffset(i);
        if(fast){noTr(function(){se.style.transform='translateX('+o+'px)';});}
        else{
            s.busy=true; se.style.transform='translateX('+o+'px)';
            setTimeout(function(){
                s.busy=false;
                if(cfg.loop){if(s.i>=tot)goTo(s.i-tot,true);else if(s.i<0)goTo(s.i+tot,true);}
                updArr();
            },(cfg.transitionSpeed||600)+20);
        }
        updDots();updArr();
    }

    var ap=w.querySelector('.msb-arrow-prev'),an=w.querySelector('.msb-arrow-next');
    if(ap)ap.addEventListener('click',function(){ra();goTo(s.i-1);});
    if(an)an.addEventListener('click',function(){ra();goTo(s.i+1);});
    function updArr(){if(!ap||!an)return;ap.disabled=!cfg.loop&&s.i<=0;an.disabled=!cfg.loop&&s.i>=tot-vis();}

    var de=w.querySelector('.msb-dots');
    function buildDots(){
        if(!de)return;de.innerHTML='';
        var c=cfg.loop?tot:Math.max(1,tot-vis()+1);
        for(var i=0;i<c;i++){
            var dot=document.createElement('button');
            dot.className='msb-dot'+(i===0?' is-active':'');
            dot.setAttribute('role','tab');
            dot.setAttribute('aria-label','Slide '+(i+1));
            (function(idx){dot.addEventListener('click',function(){ra();goTo(idx);});})(i);
            de.appendChild(dot);
        }
    }
    function updDots(){
        if(!de)return;
        var idx=((s.i%tot)+tot)%tot;
        de.querySelectorAll('.msb-dot').forEach(function(d,i){d.classList.toggle('is-active',i===idx);});
    }

    function startAuto(){if(!cfg.autoplay)return;s.t=setInterval(function(){goTo(s.i+1);},(cfg.autoplaySpeed||4000));}
    function stopAuto(){clearInterval(s.t);}
    function ra(){stopAuto();startAuto();}

    w.addEventListener('mouseenter',stopAuto);
    w.addEventListener('mouseleave',startAuto);

    // Drag/touch
    var dr={on:false,x0:0};
    w.addEventListener('mousedown',function(e){dr.on=true;dr.x0=e.clientX;moved=false;w._msbMoved=false;se.classList.add('no-tr');w.style.cursor='grabbing';stopAuto();});
    window.addEventListener('mousemove',function(e){if(!dr.on)return;moved=moved||Math.abs(e.clientX-dr.x0)>6;w._msbMoved=moved;se.style.transform='translateX('+(getOffset(s.i)+e.clientX-dr.x0)+'px)';});
    window.addEventListener('mouseup',function(e){if(!dr.on)return;dr.on=false;se.classList.remove('no-tr');w.style.cursor='';var d=e.clientX-dr.x0;if(d<-50)goTo(s.i+1);else if(d>50)goTo(s.i-1);else goTo(s.i,true);startAuto();});
    w.addEventListener('touchstart',function(e){dr.on=true;dr.x0=e.touches[0].clientX;moved=false;w._msbMoved=false;se.classList.add('no-tr');stopAuto();},{passive:true});
    w.addEventListener('touchmove',function(e){if(!dr.on)return;moved=moved||Math.abs(e.touches[0].clientX-dr.x0)>6;w._msbMoved=moved;se.style.transform='translateX('+(getOffset(s.i)+e.touches[0].clientX-dr.x0)+'px)';},{passive:true});
    w.addEventListener('touchend',function(e){dr.on=false;se.classList.remove('no-tr');var d=e.changedTouches[0].clientX-dr.x0;if(d<-50)goTo(s.i+1);else if(d>50)goTo(s.i-1);else goTo(s.i,true);startAuto();});

    w.addEventListener('click',function(e){
        var trigger=e.target.closest('.msb-slide-lightbox-trigger');
        if(!trigger||!w.contains(trigger))return;
        if(moved){e.preventDefault();return;}
        var src=trigger.dataset.msbFull||'';
        var idx=Math.max(0,gallery.findIndex(function(x){return x.src===src;}));
        e.preventDefault();
        ra();
        ensureLightbox().open(gallery,idx,trigger);
    });

    w.setAttribute('tabindex','0');
    w.addEventListener('keydown',function(e){if(e.key==='ArrowLeft'){e.preventDefault();ra();goTo(s.i-1);}if(e.key==='ArrowRight'){e.preventDefault();ra();goTo(s.i+1);}});

    var rt;window.addEventListener('resize',function(){clearTimeout(rt);rt=setTimeout(function(){setup();buildDots();goTo(s.i,true);},150);});
    setup();buildDots();goTo(0,true);updArr();startAuto();
}
document.readyState==='loading'?document.addEventListener('DOMContentLoaded',initAll):initAll();
}());
