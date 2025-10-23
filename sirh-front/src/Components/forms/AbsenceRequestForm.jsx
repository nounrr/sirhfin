import React, { useEffect } from 'react';
import { useDispatch, useSelector } from 'react-redux';
import { createAbsenceRequest, fetchAbsenceRequests, updateAbsenceRequest } from '../../Redux/Slices/absenceRequestSlice';
import { Formik, Form, Field, ErrorMessage,useFormikContext } from 'formik';
import * as Yup from 'yup';
import Swal from 'sweetalert2';
import { Icon } from '@iconify/react';
import LeaveValidationWidget from '../LeaveValidationWidget';


function TodayDateSetter() {
  // Ce composant se charge de mettre à jour les dates si type=AttestationTravail
  const { values, setFieldValue } = useFormikContext();
  useEffect(() => {
    if (values.type === "AttestationTravail") {
      const today = new Date().toISOString().split('T')[0];
      setFieldValue('dateDebut', today);
      setFieldValue('dateFin', today);
    }
  }, [values.type, setFieldValue]);
  return null;
}


const AbsenceRequestForm = ({ initialValues = {}, isEdit = false, onSuccess }) => {
  const dispatch = useDispatch();
  const { status } = useSelector(state => state.absenceRequests);
  const { user, isLoading: authLoading } = useSelector(state => state.auth);
  const role = useSelector(state => state.auth.roles);
  // Debug initialValues
  useEffect(() => {
    console.log('Initial values:', initialValues);
    console.log('Is edit mode:', isEdit);
    console.log('Current user:', user);
  }, [initialValues, isEdit, user]);

  useEffect(() => {
    if (!isEdit && !user) {
      Swal.fire('Erreur', 'Vous devez être connecté pour créer une demande d\'absence', 'error');
    }
  }, [user, isEdit]);

  // Vérification des permissions pour l'édition
  useEffect(() => {
    if (isEdit && role && !role.includes('RH') && !role.includes('Gest_RH')) {
      Swal.fire({
        title: 'Accès refusé',
        text: 'Seuls les utilisateurs RH peuvent modifier les demandes d\'absence.',
        icon: 'error',
        confirmButtonText: 'Retour'
      }).then(() => {
        if (onSuccess) onSuccess();
      });
    }
  }, [isEdit, role, onSuccess]);

  const validationSchema = Yup.object({
    type: Yup.string()
      .required('Le type d\'absence est requis')
      .oneOf(['Congé', 'maladie', 'autre', 'AttestationTravail'], 'Type d\'absence invalide'),
    statut: Yup.string()
      .oneOf(['en_attente', 'rejeté', 'validé', 'approuvé', 'annulé'], 'Statut invalide'),
    dateDebut: Yup.date().required('La date de début est requise'),
    dateFin: Yup.date()
      .required('La date de fin est requise')
      .min(Yup.ref('dateDebut'), 'La date de fin doit être postérieure à la date de début'),
    motif: Yup.string().nullable(),
    justification: Yup.mixed()
      .nullable()
      .test('fileSize', 'Le fichier est trop volumineux (max 2MB)', value => {
        if (!value || typeof value === 'string') return true;
        return value.size <= 2048 * 1024;
      })
      .test('fileType', 'Format de fichier non supporté (jpg, jpeg, png, pdf uniquement)', value => {
        if (!value || typeof value === 'string') return true;
        return ['image/jpeg', 'image/png', 'application/pdf'].includes(value.type);
      })
  });

  const handleSubmit = async (values, { setSubmitting, resetForm }) => {
    try {
      if (isEdit) {
        // For update
        const userId = initialValues?.user_id;
        if (!userId) {
          throw new Error('Impossible de déterminer l\'utilisateur pour la mise à jour');
        }
        
        // Create FormData
        const formData = new FormData();
        
        // Add required fields with fallback to initialValues
        formData.append('user_id', String(userId));
        formData.append('type', values.type || initialValues.type);
        formData.append('dateDebut', new Date(values.dateDebut || initialValues.dateDebut).toISOString().split('T')[0]);
        formData.append('dateFin', new Date(values.dateFin || initialValues.dateFin).toISOString().split('T')[0]);
        formData.append('statut', values.statut || initialValues.statut || 'en_attente');
        
        // Add optional fields if they exist
        if (values.motif || initialValues.motif) {
          formData.append('motif', values.motif || initialValues.motif);
        }

        // Handle justification file
        if (values.justification instanceof File) {
          console.log('Adding file to FormData:', values.justification);
          formData.append('justification', values.justification);
        } else if (values.justification === null || values.justification === '') {
          formData.append('justification', '');
        } else if (values.justification && !(values.justification instanceof File)) {
            formData.append('justification', values.justification);
        }

        // Log FormData contents
        console.log('FormData contents for update:');
        for (let [key, value] of formData.entries()) {
          if (value instanceof File) {
            console.log(`${key}: File(${value.name}, ${value.type}, ${value.size} bytes)`);
          } else {
            console.log(`${key}: ${value}`);
          }
        }

        // Convert FormData to plain object
        const requestData = {};
        for (let [key, value] of formData.entries()) {
          requestData[key] = value;
        }

        console.log('Sending request data:', requestData);

        const response = await dispatch(
          updateAbsenceRequest({ id: initialValues.id, data: requestData })
        ).unwrap();

        if (response && response.error) {
          throw new Error(response.error);
        }

        resetForm();
        if (onSuccess) {
          onSuccess();
          dispatch(fetchAbsenceRequests())
        };
      } else {
        // For create
        if (!user) {
          throw new Error('Utilisateur non authentifié');
        }

        const formData = new FormData();
        
        // Add required fields
        formData.append('user_id', String(user.id));
        formData.append('type', values.type);
        formData.append('dateDebut', new Date(values.dateDebut).toISOString().split('T')[0]);
        formData.append('dateFin', new Date(values.dateFin).toISOString().split('T')[0]);
        formData.append('statut', 'en_attente');
        
        // Add optional fields if they exist
        if (values.motif) {
          formData.append('motif', values.motif);
        }

        if (values.justification instanceof File) {
          console.log('Adding file to FormData:', values.justification);
          formData.append('justification', values.justification);
        } else if (values.justification === null || values.justification === '') {
          formData.append('justification', '');
        } else if (values.justification) {
          formData.append('justification', values.justification);
        }

        const response = await dispatch(createAbsenceRequest(formData)).unwrap();

        if (response && response.error) {
          throw new Error(response.error);
        }

        resetForm();
        if (onSuccess) {onSuccess()
          dispatch(fetchAbsenceRequests())
        };
      }
    } catch (error) {
      console.error('Error submitting form:', error);
      let errorMessage = 'Une erreur est survenue lors de la mise à jour de la demande d\'absence.';
      
      if (error.error) {
        errorMessage = Object.entries(error.error)
          .map(([field, messages]) => `${field}: ${messages.join(', ')}`)
          .join('\n');
      } else if (error.message) {
        errorMessage = error.message;
      }

      Swal.fire('Erreur!', errorMessage, 'error');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="card border-0 shadow-lg rounded-4">
      <div className="card-body p-4">
        <Formik
          initialValues={{
            type: 'Congé',
            dateDebut: '',
            dateFin: '',
            motif: '',
            justification: null,
            ...initialValues
          }}
          validationSchema={validationSchema}
          onSubmit={handleSubmit}
        >
          {({ isSubmitting, setFieldValue, values }) => (
            <Form>
              <TodayDateSetter />

              {/* Informations de l'employé */}
              {!isEdit && user && role && !role.includes('Employe') && (
                <div className="mb-4">
                  <div className="d-flex align-items-center gap-2 mb-3">
                    <Icon icon="mdi:account" className="text-primary" style={{ fontSize: '1.3rem' }} />
                    <h5 className="fw-bold mb-0">Informations de l'employé</h5>
                  </div>
                  <div className="card border-2 border-dashed" style={{ borderColor: '#667eea' }}>
                    <div className="card-body p-3">
                      <label className="form-label fw-semibold d-flex align-items-center gap-2">
                        <Icon icon="mdi:account-circle" className="text-primary" />
                        Employé
                      </label>
                      <input
                        type="text"
                        className="form-control form-control-lg"
                        value={`${user.name} ${user.prenom}`}
                        disabled
                        style={{ borderRadius: '12px', backgroundColor: '#f8f9fa' }}
                      />
                    </div>
                  </div>
                </div>
              )}

              {/* Type de demande et statut */}
              <div className="mb-4">
                <div className="d-flex align-items-center gap-2 mb-3">
                  <Icon icon="mdi:clipboard-text" className="text-primary" style={{ fontSize: '1.3rem' }} />
                  <h5 className="fw-bold mb-0">Type de demande</h5>
                </div>
                
                <div className="row g-3">
                  <div className="col-md-4">
                    <label htmlFor="type" className="form-label fw-semibold d-flex align-items-center gap-2">
                      <Icon icon="mdi:format-list-bulleted" className="text-primary" />
                      Type de demande <span className="text-danger">*</span>
                    </label>
                    <Field as="select" name="type" className="form-select form-select-lg" style={{ borderRadius: '12px' }}>
                      <option value="Congé">Congé</option>
                      <option value="maladie">Maladie</option>
                      <option value="AttestationTravail">Attestation de travail</option>
                      <option value="autre">Autre</option>
                    </Field>
                    <ErrorMessage name="type" component="div" className="invalid-feedback d-flex align-items-center gap-1 mt-2" />
                  </div>
                  {isEdit && role && !role.includes('Employe') && (
                    <div className="col-md-4">
                      <label htmlFor="statut" className="form-label fw-semibold d-flex align-items-center gap-2">
                        <Icon icon="mdi:check-circle" className="text-success" />
                        Statut
                      </label>
                      <Field as="select" name="statut" className="form-select form-select-lg" style={{ borderRadius: '12px' }}>
                        <option value="en_attente">En attente</option>
                        <option value="rejeté">Rejeté</option>
                        <option value="validé">Validé</option>
                        <option value="annulé">Annulé</option>
                        {isEdit && role && role.includes('RH') && (
                          <option value="approuvé">Approuvé</option>
                        )}
                      </Field>
                      <ErrorMessage name="statut" component="div" className="invalid-feedback d-flex align-items-center gap-1 mt-2" />
                    </div>
                  )}
                  {values.type !== "AttestationTravail" && (
                    <div className={isEdit && role && !role.includes('Employe') ? "col-md-4" : "col-md-8"}>
                      <label htmlFor="justification" className="form-label fw-semibold d-flex align-items-center gap-2">
                        <Icon icon="mdi:paperclip" className="text-primary" />
                        Pièce justificative
                      </label>
                      <input
                        type="file"
                        name="justification"
                        id="justification"
                        className="form-control form-control-lg"
                        onChange={(event) => {
                          setFieldValue("justification", event.currentTarget.files[0]);
                        }}
                        style={{ borderRadius: '12px' }}
                      />
                      {values.justification && typeof values.justification === 'string' && (
                        <div className="mt-2">
                          <div className="alert alert-info d-flex align-items-center gap-2">
                            <Icon icon="mdi:file-check" />
                            <small>Fichier actuel: {values.justification}</small>
                          </div>
                        </div>
                      )}
                      <small className="text-muted mt-1 d-block">
                        Formats acceptés: JPG, PNG, PDF (max 2MB)
                      </small>
                      <ErrorMessage name="justification" component="div" className="invalid-feedback d-flex align-items-center gap-1 mt-2" />
                    </div>
                  )}
                </div>
              </div>

              {/* Période d'absence */}
              {values.type !== "AttestationTravail" && (
                <div className="mb-4">
                  <div className="d-flex align-items-center gap-2 mb-3">
                    <Icon icon="mdi:calendar-range" className="text-primary" style={{ fontSize: '1.3rem' }} />
                    <h5 className="fw-bold mb-0">Période d'absence</h5>
                  </div>
                  
                  <div className="row g-3">
                    <div className="col-md-6">
                      <label htmlFor="dateDebut" className="form-label fw-semibold d-flex align-items-center gap-2">
                        <Icon icon="mdi:calendar-start" className="text-success" />
                        Date de début <span className="text-danger">*</span>
                      </label>
                      <Field
                        type="date"
                        name="dateDebut"
                        id="dateDebut"
                        className="form-control form-control-lg"
                        style={{ borderRadius: '12px' }}
                      />
                      <ErrorMessage name="dateDebut" component="div" className="invalid-feedback d-flex align-items-center gap-1 mt-2" />
                    </div>
                    <div className="col-md-6">
                      <label htmlFor="dateFin" className="form-label fw-semibold d-flex align-items-center gap-2">
                        <Icon icon="mdi:calendar-end" className="text-warning" />
                        Date de fin <span className="text-danger">*</span>
                      </label>
                      <Field
                        type="date"
                        name="dateFin"
                        id="dateFin"
                        className="form-control form-control-lg"
                        style={{ borderRadius: '12px' }}
                      />
                      <ErrorMessage name="dateFin" component="div" className="invalid-feedback d-flex align-items-center gap-1 mt-2" />
                    </div>
                  </div>
                </div>
              )}

              {/* Widget de validation des congés */}
              {values.type === "Congé" && values.dateDebut && values.dateFin && (
                <div className="mb-4">
                  <LeaveValidationWidget
                    userId={user?.id}
                    leaveType="Congé"
                    startDate={values.dateDebut}
                    endDate={values.dateFin}
                  />
                </div>
              )}

              {values.type === "AttestationTravail" && (
                <>
                  <Field type="hidden" name="dateDebut" />
                  <Field type="hidden" name="dateFin" />
                </>
              )}

              {/* Motif */}
              <div className="mb-4">
                <div className="d-flex align-items-center gap-2 mb-3">
                  <Icon icon="mdi:text-box" className="text-primary" style={{ fontSize: '1.3rem' }} />
                  <h5 className="fw-bold mb-0">Motif de la demande</h5>
                </div>
                
                <label htmlFor="motif" className="form-label fw-semibold d-flex align-items-center gap-2">
                  <Icon icon="mdi:message-text" className="text-primary" />
                  Motif
                </label>
                <Field
                  as="textarea"
                  name="motif"
                  id="motif"
                  className="form-control"
                  rows="4"
                  placeholder="Décrivez le motif de votre demande d'absence..."
                  style={{ borderRadius: '12px', resize: 'vertical' }}
                />
                <ErrorMessage name="motif" component="div" className="invalid-feedback d-flex align-items-center gap-1 mt-2" />
              </div>

              {/* Actions */}
              <div className="d-flex justify-content-end gap-3 pt-3 border-top">
                <button 
                  type="button" 
                  onClick={onSuccess}
                  className="btn btn-outline-secondary d-flex align-items-center gap-2 px-4 py-2"
                  style={{ 
                    borderRadius: '12px',
                    fontSize: '1.1rem'
                  }}
                >
                  <Icon icon="mdi:cancel" />
                  Annuler
                </button>
                <button 
                  type="submit" 
                  disabled={isSubmitting} 
                  className="btn btn-primary d-flex align-items-center gap-2 px-4 py-2"
                  style={{ 
                    borderRadius: '12px',
                    background: isSubmitting ? '#6c757d' : 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
                    border: 'none',
                    fontSize: '1.1rem'
                  }}
                >
                  {isSubmitting ? (
                    <>
                      <div className="spinner-border spinner-border-sm" role="status">
                        <span className="visually-hidden">Loading...</span>
                      </div>
                      Enregistrement...
                    </>
                  ) : (
                    <>
                      <Icon icon={isEdit ? "mdi:content-save" : "mdi:send"} />
                      {isEdit ? 'Mettre à jour' : 'Soumettre la demande'}
                    </>
                  )}
                </button>
              </div>
            </Form>
          )}
        </Formik>
      </div>

      {/* CSS pour les animations */}
      <style jsx>{`
        .btn:hover:not(:disabled) {
          transform: translateY(-2px);
          transition: transform 0.2s ease;
        }
        .form-control:focus, .form-select:focus {
          border-color: #667eea;
          box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .card {
          transition: all 0.3s ease;
        }
        .card:hover {
          transform: translateY(-2px);
        }
      `}</style>
    </div>
  );
};

export default AbsenceRequestForm;