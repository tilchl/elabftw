describe('Experiments', () => {
  beforeEach(() => {
    cy.login();
    cy.enableCodeCoverage(Cypress.currentTest.titlePath.join(' '));
  });

  const entityEdit = () => {
    cy.url().should('include', 'mode=edit');
    // update date
    cy.get('#date_input').type('2021-05-01').blur();
    cy.get('#overlay').should('be.visible').should('contain', 'Saved');

    // update title
    cy.get('#title_input').type('Updated from cypress').blur();
    cy.get('#overlay').should('be.visible').should('contain', 'Saved');

    // create Tag
    cy.get('#createTagInput').type('some tag').blur();
    cy.get('#overlay').should('be.visible').should('contain', 'Saved');
    cy.get('div.tags').contains('some tag').should('exist');

    // delete tag
    cy.window().then(win => {
      cy.stub(win, 'confirm').returns(true);
      cy.contains('some tag').click();
    });
    cy.get('div.tags').contains('some tag').should('not.exist');

    // create step
    cy.get('.stepinput').type('some step');
    cy.get('[data-action="create-step"').click();
    cy.get('.step-static').should('contain', 'some step');

    // complete step
    cy.get('.stepbox').click();
    cy.get('.text-muted').should('contain', 'completed');

    cy.htmlvalidate();

    // delete step
    cy.get('.stepDestroy').click();
    cy.contains('some step').should('not.exist');
  };

  const entityComment = () => {
    // go in view mode
    cy.get('[title="View mode"]').click();
    cy.get('#commentsCreateArea').type('This is a very nice experiment');
    cy.get('[data-action="create-comment"]').click();
    cy.get('#comment').contains('Toto Le sysadmin commented').should('be.visible');
    cy.get('[data-action="destroy-comment"]').click();
    cy.get('#comment').contains('Toto Le sysadmin commented').should('not.exist');
    cy.htmlvalidate();
    // go back in edit mode for destroy action
    cy.get('[title="Edit"]').click();
  };

  const entityDuplicate = () => {
    // keep the original entity url in memory
    cy.url().then(url => {
      cy.log(url);
      // go in view mode
      cy.get('[title="View mode"]').click();
      cy.get('[data-action="duplicate-entity"]').click();
      cy.contains('Title').should('be.visible');
      // destroy the duplicated entity now
      entityDestroy();
      // go back to the original entity
      cy.visit(url);
    });
  };

  const entityDestroy = () => {
    cy.get('button[title="More options"]').click().get('button[data-action="destroy"]').click();
  };

  it('Create and edit an experiment', () => {
    cy.visit('/experiments.php');
    cy.htmlvalidate();
    cy.contains('Create').click();
    cy.get('#createModal_experiments').should('be.visible').should('contain', 'Default template').contains('Default template').click();
    entityEdit();
    // change status
    cy.get('#status_select').select('Success').blur();
    cy.get('#overlay').should('be.visible').should('contain', 'Saved');
    entityComment();
    entityDuplicate();
    entityDestroy();
  });

  it('Create and edit an item', () => {
    cy.visit('/database.php');
    cy.htmlvalidate();
    cy.contains('Create').click();
    cy.get('#createModal_items').should('be.visible').should('contain', 'Generated').contains('Generated').click();
    entityEdit();
    cy.get('#category_select').select('Microscope').blur();
    cy.get('#overlay').should('be.visible').should('contain', 'Saved');
    entityComment();
    entityDuplicate();
    entityDestroy();
  });
});
