<?php /* JourneyAI — closes the app shell (sidebar layout). */ ?>
  </main>
</div>
<?php include __DIR__ . '/ja-chat.php'; ?>
<script src="js/ja-chat.js" defer></script>
<script>
/* sidebar mobile toggle */
(function(){
  var t=document.getElementById('jaSideToggle'),s=document.getElementById('jaSidebar');
  if(t&&s)t.addEventListener('click',function(){s.classList.toggle('open');});
  document.addEventListener('click',function(e){
    if(s&&s.classList.contains('open')&&!s.contains(e.target)&&e.target!==t&&!t.contains(e.target))s.classList.remove('open');
  });
})();
</script>
</body>
</html>
