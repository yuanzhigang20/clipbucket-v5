(function(){
  var htmlLang=(document.documentElement.getAttribute('lang')||'').toLowerCase();
  var isZh=htmlLang.indexOf('zh')===0 || /(^|;\s*)cb_lang=(zh|zh-CN)/i.test(document.cookie||'');
  var zh={Home:'首页',Videos:'视频',Photos:'图片',Channels:'频道',Collections:'合集',Audios:'音频','All Videos':'全部视频','Latest Videos':'最新视频','Most Viewed':'最多观看',Trending:'热门',Categories:'分类',Tags:'标签','Long Videos':'长视频','HD Videos':'高清视频',Recommended:'推荐','Search videos...':'搜索视频...','Search for videos...':'搜索视频...','Search Keyword':'搜索关键词',Upload:'上传',Login:'登录','Sign Up':'注册','Create New Account':'注册',Menu:'菜单',Language:'语言'};
  var en={'首页':'Home','视频':'Videos','图片':'Photos','频道':'Channels','合集':'Collections','音频':'Audios','全部视频':'All Videos','最新视频':'Latest Videos','最多观看':'Most Viewed','热门':'Trending','分类':'Categories','标签':'Tags','长视频':'Long Videos','高清视频':'HD Videos','推荐':'Recommended','搜索视频...':'Search videos...','搜索关键词':'Search Keyword','上传':'Upload','登录':'Login','注册':'Sign Up','菜单':'Menu','语言':'Language'};
  var map=isZh?zh:en;
  function swapText(el){
    if(!el) return;
    var t=(el.textContent||'').replace(/\s+/g,' ').trim();
    if(map[t]) el.textContent=map[t];
  }
  document.querySelectorAll('.main-links a,.oc-portal-nav a,.navbar-toggle,.search-type,.right-menu a span,.btn-login,.btn-newacc,.btn-upload span,.oc-lang-switcher label').forEach(swapText);
  document.querySelectorAll('input[placeholder]').forEach(function(el){var p=(el.getAttribute('placeholder')||'').trim(); if(map[p]) el.setAttribute('placeholder',map[p]);});
  var main=document.getElementById('main'); if(main){main.setAttribute('data-home-heading', isZh?'热门视频':'Trending Videos');}
})();
