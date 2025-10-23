import { createSlice, createAsyncThunk } from '@reduxjs/toolkit';
import api from '../../config/axios';

export const fetchTypeDocs = createAsyncThunk(
  'typeDocs/fetchTypeDocs',
  async () => {
    const response = await api.get('/type-docs');
    return response.data;
  }
);

export const createTypeDoc = createAsyncThunk(
  'typeDocs/createTypeDoc',
  async (typeDocData) => {
    const response = await api.post('/type-docs', typeDocData);
    return response.data;
  }
);

export const updateTypeDoc = createAsyncThunk(
  'typeDocs/updateTypeDoc',
  async ({ id, ...typeDocData }) => {
    const response = await api.put(`/type-docs/${id}`, typeDocData);
    return response.data;
  }
);

export const deleteTypeDocs = createAsyncThunk(
  'typeDocs/deleteTypeDocs',
  async (ids) => {
    await Promise.all(ids.map(id => api.delete(`/type-docs/${id}`)));
    return ids;
  }
);

const typeDocSlice = createSlice({
  name: 'typeDocs',
  initialState: {
    items: [],
    status: 'idle',
    error: null
  },
  reducers: {},
  extraReducers: (builder) => {
    builder
      .addCase(fetchTypeDocs.pending, (state) => {
        state.status = 'loading';
      })
      .addCase(fetchTypeDocs.fulfilled, (state, action) => {
        state.status = 'succeeded';
        state.items = action.payload;
      })
      .addCase(fetchTypeDocs.rejected, (state, action) => {
        state.status = 'failed';
        state.error = action.error.message;
      })
      .addCase(createTypeDoc.fulfilled, (state, action) => {
        state.items.push(action.payload);
      })
      .addCase(updateTypeDoc.fulfilled, (state, action) => {
        const index = state.items.findIndex(item => item.id === action.payload.id);
        if (index !== -1) {
          state.items[index] = action.payload;
        }
      })
      .addCase(deleteTypeDocs.fulfilled, (state, action) => {
        state.items = state.items.filter(item => !action.payload.includes(item.id));
      });
  }
});

export default typeDocSlice.reducer;