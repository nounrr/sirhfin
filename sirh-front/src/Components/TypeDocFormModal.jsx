import React from 'react';
import { Formik, Form, Field } from 'formik';
import * as Yup from 'yup';

const TypeDocFormModal = ({ isOpen, onClose, onSubmit, initialData }) => {
  const validationSchema = Yup.object({
    nom: Yup.string()
      .required('Le nom est requis')
      .min(2, 'Le nom doit contenir au moins 2 caractères')
      .max(50, 'Le nom ne peut pas dépasser 50 caractères'),
  });

  return (
    <div className={`modal ${isOpen ? 'd-block' : ''}`} tabIndex="-1" role="dialog" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
      <div className="modal-dialog modal-dialog-centered" role="document">
        <div className="modal-content">
          <div className="modal-header">
            <h5 className="modal-title">{initialData ? 'Modifier le type de document' : 'Ajouter un type de document'}</h5>
            <button type="button" className="btn-close" onClick={onClose} aria-label="Close"></button>
          </div>
          <Formik
            initialValues={{
              nom: initialData?.nom || '',
            }}
            validationSchema={validationSchema}
            onSubmit={onSubmit}
          >
            {({ errors, touched }) => (
              <Form>
                <div className="modal-body">
                  <div className="mb-3">
                    <label htmlFor="nom" className="form-label">Nom</label>
                    <Field
                      type="text"
                      id="nom"
                      name="nom"
                      className={`form-control ${errors.nom && touched.nom ? 'is-invalid' : ''}`}
                    />
                    {errors.nom && touched.nom && (
                      <div className="invalid-feedback">{errors.nom}</div>
                    )}
                  </div>
                </div>
                <div className="modal-footer">
                  <button type="button" className="btn btn-secondary" onClick={onClose}>Annuler</button>
                  <button type="submit" className="btn btn-primary">{initialData ? 'Modifier' : 'Ajouter'}</button>
                </div>
              </Form>
            )}
          </Formik>
        </div>
      </div>
    </div>
  );
};

export default TypeDocFormModal;