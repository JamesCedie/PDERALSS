
function toggleSidebar(){
  const s=document.getElementById('sidebar'),m=document.getElementById('main');
  if(window.innerWidth<=800){s.classList.toggle('mobile-open');}
  else{s.classList.toggle('collapsed');m.classList.toggle('expanded');}
}
function openModal(id){document.getElementById(id).classList.add('show')}
function closeModal(id){document.getElementById(id).classList.remove('show')}
function confirmAction(message){return confirm(message)}
document.addEventListener('click',function(e){
  if(e.target.classList.contains('modal')) e.target.classList.remove('show');
});
