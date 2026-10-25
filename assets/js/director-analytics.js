(function () {
  'use strict';

  const normalize = value => String(value).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
  const goalPage = document.querySelector('[data-sdg-page]');
  if (goalPage && goalPage.querySelector('[data-goal-list]')) {
    const list = goalPage.querySelector('[data-goal-list]');
    const goals = Array.from(list.querySelectorAll('[data-goal]'));
    const search = document.getElementById('da-goal-search');
    const sort = document.getElementById('da-goal-sort');
    const filters = Array.from(goalPage.querySelectorAll('[data-goal-filter]'));
    let filter = 'all';
    const updateGoals = () => {
      const query = normalize(search.value);
      const numberQuery = query.replace(/^sdg\s*/, '');
      let shown = 0;
      goals.sort((a, b) => {
        const countDifference = Number(b.dataset.count) - Number(a.dataset.count);
        return (sort.value === 'number' ? 0 : sort.value === 'least' ? -countDifference : countDifference)
          || Number(a.dataset.goal) - Number(b.dataset.goal);
      }).forEach(goal => {
        const count = Number(goal.dataset.count);
        const matchesText = query === '' || goal.dataset.goal === numberQuery || normalize(goal.dataset.name).includes(query);
        goal.hidden = !matchesText || (filter === 'represented' && count === 0) || (filter === 'empty' && count > 0);
        if (!goal.hidden) shown++;
        list.appendChild(goal);
      });
      goalPage.querySelector('[data-goal-result-count]').textContent = 'Showing ' + shown + ' of 17 goals';
      goalPage.querySelector('[data-goal-empty]').hidden = shown !== 0;
    };
    filters.forEach(button => button.addEventListener('click', () => {
      filter = button.dataset.goalFilter;
      filters.forEach(item => item.setAttribute('aria-pressed', String(item === button)));
      updateGoals();
    }));
    search.addEventListener('input', updateGoals);
    sort.addEventListener('change', updateGoals);
    goalPage.querySelectorAll('[data-sdg-controls]').forEach(control => { control.hidden = false; });
    updateGoals();
  }

  const peoplePage = document.querySelector('[data-contributors-page]');
  if (peoplePage && peoplePage.querySelector('[data-role-tabs]')) {
    const tabList = peoplePage.querySelector('[data-role-tabs]');
    const tabs = Array.from(tabList.querySelectorAll('[data-role-tab]'));
    const panels = Array.from(peoplePage.querySelectorAll('[data-role-panel]'));
    const search = document.getElementById('da-people-search');
    const sort = document.getElementById('da-people-sort');
    const updatePeople = () => {
      const query = normalize(search.value);
      panels.forEach(panel => {
        const list = panel.querySelector('[data-people-list]');
        if (!list) return;
        const people = Array.from(list.querySelectorAll('[data-person-name]'));
        let shown = 0;
        people.sort((a, b) => (sort.value === 'count' ? Number(b.dataset.personCount) - Number(a.dataset.personCount) : 0)
          || a.dataset.personName.localeCompare(b.dataset.personName)
          || Number(a.dataset.personId) - Number(b.dataset.personId)).forEach(person => {
          person.hidden = !normalize(person.dataset.personName).includes(query);
          if (!person.hidden) shown++;
          list.appendChild(person);
        });
        panel.querySelector('[data-people-result-count]').textContent = 'Showing ' + shown + ' of ' + people.length + ' contributors';
        panel.querySelector('[data-people-empty]').hidden = shown !== 0;
      });
    };
    const activate = (tab, focus) => {
      tabs.forEach(item => {
        item.setAttribute('aria-selected', String(item === tab));
        item.tabIndex = item === tab ? 0 : -1;
      });
      panels.forEach(panel => { panel.hidden = panel.dataset.rolePanel !== tab.dataset.roleTab; });
      const url = new URL(window.location.href);
      url.searchParams.set('role', tab.dataset.roleTab);
      window.history.replaceState(null, '', url.pathname + url.search + url.hash);
      if (focus) tab.focus();
      updatePeople();
    };
    tabs.forEach((tab, index) => {
      tab.addEventListener('click', () => activate(tab, false));
      tab.addEventListener('keydown', event => {
        let next;
        if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
        if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
        if (event.key === 'Home') next = 0;
        if (event.key === 'End') next = tabs.length - 1;
        if (next === undefined) return;
        event.preventDefault();
        activate(tabs[next], true);
      });
    });
    search.addEventListener('input', updatePeople);
    sort.addEventListener('change', updatePeople);
    tabList.hidden = false;
    peoplePage.querySelector('[data-people-controls]').hidden = false;
    activate(tabs.find(tab => tab.dataset.roleTab === peoplePage.dataset.initialRole) || tabs[0], false);
  }
  const root = document.documentElement;
  if (root.dataset.directorPageState === 'loading') {
    root.dataset.directorPageState = 'ready';
    document.dispatchEvent(new Event('oracle:director-state'));
  }
})();
